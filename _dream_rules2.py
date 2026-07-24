import sqlite3, json

db_path = r'C:\Users\HP\.local\share\mimocode\mimocode.db'
conn = sqlite3.connect(db_path)
cur = conn.cursor()

# Search for user text parts with rule/decision keywords
keywords = ['always', 'never', 'remember', 'must not', 'do not', 'decided', 'rule', 'important']

print("=== USER TEXT PARTS WITH RULE/DECISION KEYWORDS ===")
for kw in keywords:
    cur.execute("""
        SELECT p.session_id, substr(json_extract(p.data, '$.text'), 1, 400) as preview
        FROM part p
        WHERE json_extract(p.data, '$.type') = 'text'
          AND json_extract(p.data, '$.text') LIKE ?
        ORDER BY p.time_created DESC
        LIMIT 5
    """, (f'%{kw}%',))
    rows = cur.fetchall()
    if rows:
        print(f"\n--- Keyword: '{kw}' ({len(rows)} results) ---")
        for r in rows:
            print(f"  [{r[0]}] {r[1][:300]}")
            print()

conn.close()
