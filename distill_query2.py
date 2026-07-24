import sqlite3

conn = sqlite3.connect(r'C:\Users\HP\.local\share\mimocode\mimocode.db')
conn.row_factory = sqlite3.Row
c = conn.cursor()

# Get all sessions from the last 30 days (using time_updated)
thirty_days_ms = 30 * 24 * 3600 * 1000
c.execute("SELECT MAX(time_updated) as max_t FROM session")
max_t = c.fetchone()['max_t']
cutoff = max_t - thirty_days_ms

c.execute("""
    SELECT id FROM session 
    WHERE time_updated > ?
      AND title NOT LIKE 'checkpoint%'
      AND title NOT LIKE 'Auto %'
      AND title NOT LIKE 'New session%'
""", (cutoff,))
session_ids = [r['id'] for r in c.fetchall()]
placeholders = ','.join(['?']*len(session_ids))
print(f"Found {len(session_ids)} work sessions in the last 30 days")

# Group similar tool patterns across sessions
# 1. Most-edited files across sessions
print(f"\n=== MOST-EDITED FILES (file_path extracted from edit tool) ===")
c.execute(f"""
    SELECT 
        json_extract(p.data, '$.state.input') as inp
    FROM message m
    JOIN part p ON p.message_id = m.id
    WHERE json_extract(m.data, '$.role') = 'assistant'
      AND json_extract(p.data, '$.type') = 'tool'
      AND json_extract(p.data, '$.tool') = 'edit'
      AND m.session_id IN ({placeholders})
""", session_ids)
from collections import Counter
import json, re
file_counter = Counter()
for row in c.fetchall():
    try:
        inp = json.loads(row['inp'])
        fp = inp.get('file_path', '')
        # Normalize path
        fp = fp.replace('C:\\\\wamp64\\\\www\\\\studentfeedbackucsh\\\\', '')
        file_counter[fp] += 1
    except:
        pass

print("  Top 25 most-edited files:")
for fp, count in file_counter.most_common(25):
    print(f"    [{count}x] {fp}")

# 2. What session titles suggest about repeated task types
print(f"\n=== SESSION TITLE CATEGORIES ===")
c.execute(f"""
    SELECT id, title FROM session 
    WHERE time_updated > ?
      AND title NOT LIKE 'checkpoint%'
      AND title NOT LIKE 'Auto %'
      AND title NOT LIKE 'New session%'
    ORDER BY time_updated DESC
""", (cutoff,))
sessions = c.fetchall()

# Group by likely task type
fix_sessions = [s for s in sessions if 'fix' in s['title'].lower() or 'repair' in s['title'].lower()]
update_sessions = [s for s in sessions if 'update' in s['title'].lower() or 'modify' in s['title'].lower()]
color_sessions = [s for s in sessions if 'color' in s['title'].lower() or 'button' in s['title'].lower()]
results_sessions = [s for s in sessions if 'result' in s['title'].lower()]
localization_sessions = [s for s in sessions if 'lang' in s['title'].lower() or 'i18n' in s['title'].lower() or 'english' in s['title'].lower() or 'myanmar' in s['title'].lower()]
schema_sessions = [s for s in sessions if 'schema' in s['title'].lower() or 'database' in s['title'].lower() or 'migration' in s['title'].lower()]
profile_sessions = [s for s in sessions if 'profile' in s['title'].lower()]

print(f"  Fix/Repair: {len(fix_sessions)} sessions")
for s in fix_sessions[:5]:
    print(f"    [{s['id']}] {s['title']}")
print(f"  Update/Modify: {len(update_sessions)} sessions")
for s in update_sessions[:5]:
    print(f"    [{s['id']}] {s['title']}")
print(f"  Color/Button: {len(color_sessions)} sessions")
for s in color_sessions[:5]:
    print(f"    [{s['id']}] {s['title']}")
print(f"  Results: {len(results_sessions)} sessions")
for s in results_sessions[:5]:
    print(f"    [{s['id']}] {s['title']}")
print(f"  Localization: {len(localization_sessions)} sessions")
for s in localization_sessions[:5]:
    print(f"    [{s['id']}] {s['title']}")
print(f"  Schema/DB: {len(schema_sessions)} sessions")
for s in schema_sessions[:5]:
    print(f"    [{s['id']}] {s['title']}")
print(f"  Profile: {len(profile_sessions)} sessions")
for s in profile_sessions[:5]:
    print(f"    [{s['id']}] {s['title']}")

# 3. Multi-file edit patterns (same old_string or pattern across files)
print(f"\n=== CROSS-FILE EDIT PATTERNS (same edit concept across multiple files) ===")
c.execute(f"""
    SELECT 
        json_extract(p.data, '$.state.input') as inp,
        m.session_id as sid
    FROM message m
    JOIN part p ON p.message_id = m.id
    WHERE json_extract(m.data, '$.role') = 'assistant'
      AND json_extract(p.data, '$.type') = 'tool'
      AND json_extract(p.data, '$.tool') = 'edit'
      AND m.session_id IN ({placeholders})
""", session_ids)

# Find old_string patterns that appear in edits across MULTIPLE files
old_string_to_files = {}
for row in c.fetchall():
    try:
        inp = json.loads(row['inp'])
        fp = inp.get('file_path', '').replace('C:\\\\wamp64\\\\www\\\\studentfeedbackucsh\\\\', '')
        os_str = inp.get('old_string', '')[:150]
        if os_str and len(os_str) > 20:
            if os_str not in old_string_to_files:
                old_string_to_files[os_str] = set()
            old_string_to_files[os_str].add(fp)
    except:
        pass

cross_file_patterns = {k: v for k, v in old_string_to_files.items() if len(v) > 1}
print(f"  Found {len(cross_file_patterns)} patterns applied to multiple files")
for pattern, files in sorted(cross_file_patterns.items(), key=lambda x: -len(x[1]))[:10]:
    print(f"    Files={len(files)}: '{pattern[:120]}...' -> {list(files)[:5]}")

# 4. Read-then-edit chains (files frequently read before editing)
print(f"\n=== READ-THEN-EDIT PATTERNS (files often read) ===")
c.execute(f"""
    SELECT 
        json_extract(p.data, '$.state.input') as inp
    FROM message m
    JOIN part p ON p.message_id = m.id
    WHERE json_extract(m.data, '$.role') = 'assistant'
      AND json_extract(p.data, '$.type') = 'tool'
      AND json_extract(p.data, '$.tool') = 'read'
      AND m.session_id IN ({placeholders})
""", session_ids)
read_counter = Counter()
for row in c.fetchall():
    try:
        inp = json.loads(row['inp'])
        fp = inp.get('file_path', '').replace('C:\\\\wamp64\\\\www\\\\studentfeedbackucsh\\\\', '')
        read_counter[fp] += 1
    except:
        pass
print("  Top 15 most-read files:")
for fp, count in read_counter.most_common(15):
    print(f"    [{count}x] {fp}")

conn.close()
