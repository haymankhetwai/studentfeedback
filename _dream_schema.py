import sqlite3, json

db_path = r'C:\Users\HP\.local\share\mimocode\mimocode.db'
conn = sqlite3.connect(db_path)
cur = conn.cursor()

# Check message data structure
cur.execute("SELECT data FROM message WHERE data IS NOT NULL LIMIT 3")
for r in cur.fetchall():
    data = json.loads(r[0])
    print(json.dumps(data, indent=2, ensure_ascii=False)[:500])
    print("---")

# Check part data structure
print("\n=== PART DATA STRUCTURE ===")
cur.execute("SELECT data FROM part WHERE data IS NOT NULL LIMIT 5")
for r in cur.fetchall():
    data = json.loads(r[0])
    print(json.dumps(data, indent=2, ensure_ascii=False)[:500])
    print("---")

conn.close()
