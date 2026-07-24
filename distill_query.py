import sqlite3, json

conn = sqlite3.connect(r'C:\Users\HP\.local\share\mimocode\mimocode.db')
conn.row_factory = sqlite3.Row
c = conn.cursor()

# Get actual work sessions (not checkpoint-writer, not auto)
c.execute("""
    SELECT id, title, time_created, time_updated 
    FROM session 
    WHERE title NOT LIKE 'checkpoint%' 
      AND title NOT LIKE 'Auto %'
      AND title NOT LIKE 'New session%'
    ORDER BY time_updated DESC 
    LIMIT 30
""")
print("=== WORK SESSIONS ===")
for r in c.fetchall():
    print(f"  {r['id']}  {r['title'][:80]}  updated={r['time_updated']}")

# Get assistant tool usage patterns from recent sessions
# First get session IDs of work sessions
c.execute("""
    SELECT id FROM session 
    WHERE title NOT LIKE 'checkpoint%' 
      AND title NOT LIKE 'Auto %'
      AND title NOT LIKE 'New session%'
    ORDER BY time_updated DESC 
    LIMIT 20
""")
session_ids = [r['id'] for r in c.fetchall()]
placeholders = ','.join(['?']*len(session_ids))

print(f"\n=== TOOL USAGE ACROSS {len(session_ids)} SESSIONS ===")
c.execute(f"""
    SELECT json_extract(p.data, '$.tool') as tool,
           count(*) as n
    FROM message m
    JOIN part p ON p.message_id = m.id
    WHERE json_extract(m.data, '$.role') = 'assistant'
      AND json_extract(p.data, '$.type') = 'tool'
      AND m.session_id IN ({placeholders})
    GROUP BY tool
    ORDER BY n DESC
    LIMIT 30
""", session_ids)
for r in c.fetchall():
    print(f"  {r['tool']}: {r['n']}")

# Get repeated edit targets (files being edited multiple times)
print(f"\n=== REPEATED FILE EDITS ===")
c.execute(f"""
    SELECT substr(json_extract(p.data, '$.state.input'), 1, 300) as input_preview,
           count(*) as n
    FROM message m
    JOIN part p ON p.message_id = m.id
    WHERE json_extract(m.data, '$.role') = 'assistant'
      AND json_extract(p.data, '$.type') = 'tool'
      AND json_extract(p.data, '$.tool') = 'edit'
      AND m.session_id IN ({placeholders})
    GROUP BY input_preview
    HAVING n > 1
    ORDER BY n DESC
    LIMIT 30
""", session_ids)
for r in c.fetchall():
    print(f"  [{r['n']}x] {r['input_preview'][:200]}")

# Get repeated bash commands
print(f"\n=== REPEATED BASH COMMANDS ===")
c.execute(f"""
    SELECT substr(json_extract(p.data, '$.state.input'), 1, 300) as input_preview,
           count(*) as n
    FROM message m
    JOIN part p ON p.message_id = m.id
    WHERE json_extract(m.data, '$.role') = 'assistant'
      AND json_extract(p.data, '$.type') = 'tool'
      AND json_extract(p.data, '$.tool') = 'bash'
      AND m.session_id IN ({placeholders})
    GROUP BY input_preview
    HAVING n > 1
    ORDER BY n DESC
    LIMIT 30
""", session_ids)
for r in c.fetchall():
    print(f"  [{r['n']}x] {r['input_preview'][:200]}")

# Get repeated write calls
print(f"\n=== REPEATED WRITE CALLS ===")
c.execute(f"""
    SELECT substr(json_extract(p.data, '$.state.input'), 1, 300) as input_preview,
           count(*) as n
    FROM message m
    JOIN part p ON p.message_id = m.id
    WHERE json_extract(m.data, '$.role') = 'assistant'
      AND json_extract(p.data, '$.type') = 'tool'
      AND json_extract(p.data, '$.tool') = 'write'
      AND m.session_id IN ({placeholders})
    GROUP BY input_preview
    HAVING n > 1
    ORDER BY n DESC
    LIMIT 20
""", session_ids)
for r in c.fetchall():
    print(f"  [{r['n']}x] {r['input_preview'][:200]}")

# User messages with repeat keywords
print(f"\n=== USER MESSAGES WITH REPEAT KEYWORDS ===")
c.execute(f"""
    SELECT substr(json_extract(m.data, '$.content'), 1, 200) as msg
    FROM message m
    WHERE json_extract(m.data, '$.role') = 'user'
      AND m.session_id IN ({placeholders})
      AND (json_extract(m.data, '$.content') LIKE '%again%'
           OR json_extract(m.data, '$.content') LIKE '%every time%'
           OR json_extract(m.data, '$.content') LIKE '%like last time%'
           OR json_extract(m.data, '$.content') LIKE '%same as%'
           OR json_extract(m.data, '$.content') LIKE '%the usual%'
           OR json_extract(m.data, '$.content') LIKE '%repeat%')
    LIMIT 20
""", session_ids)
for r in c.fetchall():
    print(f"  {r['msg']}")

conn.close()
