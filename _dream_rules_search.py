import sqlite3, json

db_path = r'C:\Users\HP\.local\share\mimocode\mimocode.db'
project_id = '047425a4-14a8-47a6-b2bf-85ac6b97989c'

conn = sqlite3.connect(db_path)
cur = conn.cursor()

# Search for user messages containing rule/decision keywords
keywords = ['always', 'never', 'remember', 'rule', 'must', 'do not', 'decided', 'reason']

print("=== USER MESSAGES WITH RULE/DECISION KEYWORDS ===")
for kw in keywords:
    cur.execute("""
        SELECT m.id, m.session_id, substr(json_extract(m.data, '$.content'), 1, 300) as preview
        FROM message m
        WHERE json_extract(m.data, '$.role') = 'user'
          AND json_extract(m.data, '$.content') LIKE ?
        ORDER BY m.time_created DESC
        LIMIT 5
    """, (f'%{kw}%',))
    rows = cur.fetchall()
    if rows:
        print(f"\n--- Keyword: '{kw}' ---")
        for r in rows:
            print(f"  [{r[1]}] {r[2][:200]}")

# Search for assistant messages with "decision" or "decided"
print("\n\n=== ASSISTANT MESSAGES WITH DECISIONS ===")
cur.execute("""
    SELECT m.session_id, substr(json_extract(m.data, '$.content'), 1, 300) as preview
    FROM message m
    WHERE json_extract(m.data, '$.role') = 'assistant'
      AND (json_extract(m.data, '$.content') LIKE '%decision%' 
           OR json_extract(m.data, '$.content') LIKE '%decided%'
           OR json_extract(m.data, '$.content') LIKE '%tradeoff%')
    ORDER BY m.time_created DESC
    LIMIT 10
""")
for r in cur.fetchall():
    print(f"  [{r[0]}] {r[1][:200]}")

# Search for user messages in Burmese/Myanmar if any
print("\n\n=== USER MESSAGES (last 30, checking for non-English) ===")
cur.execute("""
    SELECT m.session_id, substr(json_extract(m.data, '$.content'), 1, 400) as preview
    FROM message m
    WHERE json_extract(m.data, '$.role') = 'user'
    ORDER BY m.time_created DESC
    LIMIT 30
""")
for r in cur.fetchall():
    preview = r[1] if r[1] else "(empty)"
    print(f"  [{r[0]}] {preview[:250]}")

conn.close()
