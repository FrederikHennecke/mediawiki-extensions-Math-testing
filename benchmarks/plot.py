import csv
xs_b, xs_c, miss, hit = [], [], [], []
with open('/tmp/math_localchecker_profile.csv') as f:
    r = csv.DictReader(f)
    for row in r:
        xs_b.append(int(row['len_bytes']))
        xs_c.append(int(row['len_chars']))
        miss.append(float(row['miss_ms']))
        hit.append(float(row['hit_ms']))

# scatter: length (chars) vs runtime
import matplotlib.pyplot as plt
plt.scatter(xs_c, miss, label='MISS', s=10)
plt.scatter(xs_c, hit, label='HIT', s=10)
plt.yscale('log')
plt.xlabel('TeX length (chars)')
plt.ylabel('Runtime (ms)')
plt.legend()
plt.show()
