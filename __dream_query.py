import sqlite3
import json

DB_PATH = r"C:\Users\HP\.local\share\mimocode\mimocode.db"
conn = sqlite3.connect(DB_PATH)
c = conn.cursor()

def safe_str(val, limit=250):
    if val is None:
        return "(None)"
    s = str(val)
    return s[:limit] if len(s) > limit else s

# Get user messages from the DATETIME session
print("=== DATETIME SESSION USER MESSAGES ===")
c.execute("""
SELECT m.id, json_extract(m.data, '$.role') as role, 
       json_extract(m.data, '$.content') as content
FROM message m 
WHERE m.session_id = 'ses_0a8cccb2effeLQSpnT8ZE3qIY2'
AND json_extract(m.data, '$.role') = 'user'
ORDER BY m.time_created
""")
for r in c.fetchall():
    print(f"  [{r[0]}] {safe_str(r[2], 350)}")

# Get assistant tool calls from DATETIME session
print("\n=== DATETIME SESSION TOOL CALLS (write/edit) ===")
c.execute("""
SELECT p.id, json_extract(p.data, '$.tool') as tool,
       json_extract(p.data, '$.state') as state
FROM message m
JOIN part p ON p.message_id = m.id
WHERE m.session_id = 'ses_0a8cccb2effeLQSpnT8ZE3qIY2'
AND json_extract(m.data, '$.role') = 'assistant'
AND json_extract(p.data, '$.tool') IN ('write', 'edit')
ORDER BY m.time_created, p.time_created
""")
for r in c.fetchall():
    state = r[2]
    if state:
        s = json.loads(state) if isinstance(state, str) else state
        inp = s.get('input', {})
        path = inp.get('file_path', inp.get('path', ''))
        print(f"  [{r[0]}] {r[1]} | {path}")
    else:
        print(f"  [{r[0]}] {r[1]} | (no state)")

# Get user messages from landing redesign session
print("\n=== LANDING REDESIGN USER MESSAGES ===")
c.execute("""
SELECT m.id, json_extract(m.data, '$.content') as content
FROM message m 
WHERE m.session_id = 'ses_0a5b9be7fffe6u2Et5SSOwwUgB'
AND json_extract(m.data, '$.role') = 'user'
ORDER BY m.time_created
""")
for r in c.fetchall():
    print(f"  [{r[0]}] {safe_str(r[1], 400)}")

# Get user messages from SA feedback session
print("\n=== SA FEEDBACK SESSION USER MESSAGES ===")
c.execute("""
SELECT m.id, json_extract(m.data, '$.content') as content
FROM message m 
WHERE m.session_id = 'ses_0b8810339ffeF7OKDbchQn63AF'
AND json_extract(m.data, '$.role') = 'user'
ORDER BY m.time_created
""")
for r in c.fetchall():
    print(f"  [{r[0]}] {safe_str(r[1], 400)}")

# Search for user statements with key patterns across ALL recent sessions
print("\n=== USER STATEMENTS WITH RULES/DECISIONS (last 7 days) ===")
c.execute("""
SELECT m.session_id, json_extract(m.data, '$.content') as content
FROM message m
WHERE json_extract(m.data, '$.role') = 'user'
AND m.time_created > 1783600000000
AND json_extract(m.data, '$.content') IS NOT NULL
AND (
    json_extract(m.data, '$.content') LIKE '%always%'
    OR json_extract(m.data, '$.content') LIKE '%never%'
    OR json_extract(m.data, '$.content') LIKE '%remember%'
    OR json_extract(m.data, '$.content') LIKE '%must%'
    OR json_extract(m.data, '$.content') LIKE '%do not%'
)
ORDER BY m.time_created
""")
for r in c.fetchall():
    print(f"  [{r[0]}] {safe_str(r[1], 350)}")

# Check for DATETIME-related file changes
print("\n=== DATETIME SESSION FILE WRITES/EDITS ===")
c.execute("""
SELECT p.id,
       json_extract(p.data, '$.tool') as tool,
       json_extract(p.data, '$.state') as state
FROM message m
JOIN part p ON p.message_id = m.id
WHERE m.session_id = 'ses_0a8cccb2effeLQSpnT8ZE3qIY2'
AND json_extract(m.data, '$.role') = 'assistant'
AND json_extract(p.data, '$.tool') IN ('write', 'edit')
ORDER BY m.time_created, p.time_created
""")
for r in c.fetchall():
    state = r[2]
    if state:
        s = json.loads(state) if isinstance(state, str) else state
        inp = s.get('input', {})
        path = inp.get('file_path', inp.get('path', ''))
        old = inp.get('old_string', '')[:80] if 'old_string' in inp else ''
        content_preview = inp.get('content', '')[:80] if 'content' in inp else ''
        print(f"  [{r[0]}] {r[1]} | {path} | {old or content_preview}")
    else:
        print(f"  [{r[0]}] {r[1]} | (no state)")

# Read the checkpoint from DATETIME session's checkpoint writer
print("\n=== DATETIME SESSION CHECKPOINT (from writer ses_0a8cba023ffeLWXfpXvsCxH53P) ===")
c.execute("""
SELECT json_extract(p.data, '$.state') as state
FROM part p
JOIN message m ON p.message_id = m.id
WHERE m.session_id = 'ses_0a8cba023ffeLWXfpXvsCxH53P'
AND json_extract(p.data, '$.type') = 'checkpoint'
LIMIT 1
""")
for r in c.fetchall():
    if r[0]:
        s = json.loads(r[0]) if isinstance(r[0], str) else r[0]
        # Print relevant sections
        for key in ['active_intent', 'next_action', 'discovered_knowledge', 'errors_and_fixes', 'design_decisions']:
            if key in s:
                print(f"\n  {key}:")
                print(f"  {safe_str(s[key], 500)}")

# Read the DATETIME checkpoint file directly
print("\n=== DATETIME SESSION CHECKPOINT FILE ===")
c.close()
conn.close()
