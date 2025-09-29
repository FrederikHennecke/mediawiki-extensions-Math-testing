<?php
declare( strict_types=1 );

namespace Bench\Math;

use MediaWiki\Extension\Math\InputCheck\InputCheckFactory;
use MediaWiki\Extension\Math\Math;
use MediaWiki\MediaWikiServices;
use ObjectCache;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;

#[BeforeMethods( [ 'setUp' ] )]
final class MathLocalCheckerBench {
	private const REF_JSON_REL = 'extensions/Math/tests/phpunit/integration/WikiTexVC/data/reference.json';
	private InputCheckFactory $factory;

	/** Full corpus: each item = ['input' => string, 'output' => string] */
	private array $cases = [];

	/** Pre-parsed expected inner DOMs keyed by index */
	private array $expectedDom = [];


	public function setUp(): void {
		$this->factory = Math::getCheckerFactory();

		$jsonPath = ( defined( 'MW_INSTALL_PATH' ) ? rtrim( MW_INSTALL_PATH, '/' ) . '/' : '' )
			. self::REF_JSON_REL;

		$this->cases = $this->loadCasesFromJson( $jsonPath );
		if ( !$this->cases ) {
			throw new \RuntimeException( "reference.json empty or unreadable at: $jsonPath" );
		}

		// Build unique inputs and pre-parse expected inner DOMs
		foreach ( $this->cases as $i => $c ) {
			$this->expectedDom[$i] = $this->makeInnerDom( $c['output'], isFragment: false );
		}
	}

	#[Revs( 1 )]
	#[Iterations(1)]
	// This will throw an error but it is not faulty. Just prints some information about the current caching method.
	public function benchDescribeWan(): void {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();

		$profile = $config->get( 'MainWANCache' );
		$profiles = $config->get( 'WANObjectCaches' );
		$cacheId = $profiles[$profile]['cacheId'] ?? null;

		$bag = ObjectCache::getInstance( $cacheId );

		$class = is_object( $bag ) ? get_class( $bag ) : 'unknown';
		echo( "[WAN] profile={$profile} cacheId=" . var_export( $cacheId, true ) . " class={$class}\n" );

		// Probe
		$k = $bag->makeKey( 'math-bench', 'probe', (string)mt_rand() );
		$okSet = $bag->set( $k, 'ok', 30 );  // true means write succeeded
		$val = $bag->get( $k );           // 'ok' on success
		echo( "[WAN] probe set=" . json_encode( $okSet ) . " get=" . json_encode( $val ) . "\n" );
	}

	/** Parse JSON into [['input' => ..., 'output' => ...], ...] */
	private function loadCasesFromJson( string $path ): array {
		if ( !is_readable( $path ) ) {
			return [];
		}
		$raw = file_get_contents( $path );
		if ( $raw === false ) {
			return [];
		}
		$data = json_decode( $raw, true );
		if ( !is_array( $data ) ) {
			return [];
		}

		$out = [];
		foreach ( $data as $row ) {
			if ( !is_array( $row ) ) {
				continue;
			}
			if ( !empty( $row['skipped'] ) ) {
				continue;
			}
			$tex = $row['input'] ?? null;
			$mml = $row['output'] ?? null;
			if ( is_string( $tex ) && $tex !== '' && is_string( $mml ) && $mml !== '' ) {
				$out[] = [ 'input' => $tex, 'output' => $mml ];
			}
		}
		return $out;
	}

	/**
	 * Return a DOMDocument whose root contains ONLY the children of <math>.
	 * If $isFragment, wrap string in <math> first so it parses.
	 */
	private function makeInnerDom( string $xml, bool $isFragment ): \DOMDocument {
		$prev = libxml_use_internal_errors( true );

		$dom = new \DOMDocument();
		$dom->preserveWhiteSpace = false;

		if ( $isFragment ) {
			$xml = '<math xmlns="http://www.w3.org/1998/Math/MathML">' . $xml . '</math>';
		}

		if ( !$dom->loadXML( $xml, LIBXML_NONET | LIBXML_NOBLANKS ) ) {
			$errs = array_map( static fn ( $e ) => trim( $e->message ), libxml_get_errors() );
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
			throw new \RuntimeException( 'Failed to parse MathML: ' . implode( ' | ', $errs ) );
		}

		$math = $dom->documentElement;
		if ( !$math || $math->localName !== 'math' ) {
			libxml_use_internal_errors( $prev );
			throw new \RuntimeException( 'Root element is not <math>.' );
		}

		$wrapDom = new \DOMDocument();
		$wrapDom->preserveWhiteSpace = false;
		$wrapper = $wrapDom->createElement( 'mwtest' );
		$wrapDom->appendChild( $wrapper );

		foreach ( iterator_to_array( $math->childNodes ) as $child ) {
			if ( $child->nodeType === XML_COMMENT_NODE ) {
				continue;
			}
			$wrapper->appendChild( $wrapDom->importNode( $child, true ) );
		}

		libxml_use_internal_errors( $prev );
		return $wrapDom;
	}

	/** Cheap structural compare via C14N of inner DOMs (ignoring <math> root). */
	private function equalXmlInner( string $actualFragment, \DOMDocument $expectedInner ): bool {
		$actualInner = $this->makeInnerDom( $actualFragment, isFragment: true );
		$left = $actualInner->C14N( false, false );
		$right = $expectedInner->C14N( false, false );
		return $left === $right;
	}

	/** Load MathML to cache */
	public function warmAll(): void {
		foreach ($this->cases as $case ) {
			$this->factory
				->newLocalChecker( $case['input'], 'tex', false )
				->getPresentationMathMLFragment();
		}
	}

	// ---------------- Subjects ----------------

	/** MISS: purge before each call (recompute + fill). */
	public function benchAllMiss(): void {
		foreach ( $this->cases as $i => $c ) {
			$out = $this->factory
				->newLocalChecker( $c['input'], 'tex', purge: true )
				->getPresentationMathMLFragment();

			assert( $this->equalXmlInner( (string)$out, $this->expectedDom[$i] ) );
		}
	}

	/** HIT: warm in same process (not timed), then run once reading from cache. */
	#[BeforeMethods( [ 'warmAll' ] )]
	public function benchAllHit(): void {
		foreach ( $this->cases as $i => $c ) {
			$out = $this->factory
				->newLocalChecker( $c['input'], 'tex', purge: false )
				->getPresentationMathMLFragment();

			assert( $this->equalXmlInner( (string)$out, $this->expectedDom[$i] ) );
		}
	}

	// The following tests don't compare the output

	public function benchAllMissCore(): void {
		foreach ( $this->cases as $c ) {
			$this->factory->newLocalChecker( $c['input'], 'tex', true )
				->getPresentationMathMLFragment();
		}
	}

	#[BeforeMethods( [ 'warmAll' ] )]
	public function benchAllHitCore(): void {
		foreach ( $this->cases as $c ) {
			$this->factory->newLocalChecker( $c['input'], 'tex', false )
				->getPresentationMathMLFragment();
		}
	}
}
