import sqlite3, json, sys

db_path = r'C:\Users\HP\.local\share\mimocode\mimocode.db'
project_id = '047425a4-14a8-47a6-b2bf-85ac6b97989c'

conn = sqlite3.connect(db_path)
cur = conn.cursor()

# Get recent sessions for this project (last 7 days)
print("=== RECENT SESSIONS (project match or all) ===")
cur.execute("""
    SELECT id, title, directory, datetime(time_created, 'unixepoch') as created,
           datetime(time_updated, 'unixepoch') as updated
    FROM session 
    WHERE directory LIKE '%studentfeedback%' OR directory LIKE '%047425a4%'
    ORDER BY time_created DESC 
    LIMIT 20
""")
rows = cur.fetchall()
if not rows:
    print("No sessions matching project. Showing all sessions:")
    cur.execute("""
        SELECT id, title, directory, datetime(time_created, 'unixepoch') as created,
               datetime(time_updated, 'unixepoch') as updated
        FROM session 
        ORDER BY time_created DESC 
        LIMIT 30
    """)
    rows = cur.fetchall()

for r in rows:
    print(f"  {r[0]} | {r[1]} | {r[2]} | created={r[3]} updated={r[4]}")

# Get message count per session
print("\n=== MESSAGE COUNTS ===")
cur.execute("""
    SELECT session_id, COUNT(*) as msg_count
    FROM message
    GROUP BY session_id
    ORDER BY MAX(time_created) DESC
    LIMIT 30
""")
for r in cur.fetchall():
    print(f"  {r[0]}: {r[1]} messages")

# Get session IDs from the memory directory
print("\n=== SESSION TABLE STATS ===")
cur.execute("SELECT COUNT(*) FROM session")
print(f"  Total sessions: {cur.fetchone()[0]}")
cur.execute("SELECT COUNT(*) FROM message")
print(f"  Total messages: {cur.fetchone()[0]}")
cur.execute("SELECT COUNT(*) FROM part")
print(f"  Total parts: {cur.fetchone()[0]}")

conn.close()
