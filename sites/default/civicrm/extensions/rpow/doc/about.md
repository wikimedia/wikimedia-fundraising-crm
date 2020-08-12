# CiviCRM Replay-on-Write: About

This is a small utility which allows CiviCRM to work with an opportunistic
combination of a read-write master database (RWDB) and read-only slave
databases (RODB).  The general idea is to connect to RODB optimistically
(expecting a typical read-only use-case) -- and then switch to RWDB *if*
there is an actual write.

Opportunistically switching to RWDB is not quite as simple as it sounds because
the original read-only session may have some significant local state (such
as session-variables or temporary-tables) that feed into the write
operations.  To mitigate this issue, we use a buffer to track statements which
affect session-state.  Such statements are replayed on RWDB.

This is designed for use-cases in which:

* *Most* users/page-views can be served *entirely* by RODB.
* *Some* users/page-views need to work with RWDB.
* There is no simpler or more correct way to predict which users/page-views will need read-write operations.
  (Or: you need a fallback in case the predictions are imperfect.)

## Classifications

Every SQL statement is (potentially) classified into one of three buckets:

* `TYPE_READ` (Ex: `SELECT * FROM foo`): The SQL statement has no side-effects; it simply reads data.
* `TYPE_BUFFER` (Ex: `SET @user_id = 123`): The SQL statement has no long-term, persistent side-effects; it can,
  however, have temporary side-effects during the present MySQL session.
* `TYPE_WRITE` (Ex: `TRUNCATE foo`): The SQL statement has long-term, persistent side-effects and must be
   executed on RWDB. (Generally, if we can't demonstrate that something is `READ` or `BUFFER`,
   then we assume it is `WRITE`.)

For more detailed examples of each category, browse [tests/examples](/tests/examples).

## Connection Stages

Each CiviCRM page-view uses a MySQL connection. Over the course of the page-view, the connection may progress through
as many as three stages:

1. (Read-only) In the first stage, we connect to RODB. We stay connected
  as long as the SQL queries are read-oriented (`TYPE_READ`). Statements
  with localized side-effects (`TYPE_BUFFER`) are copied to the buffer.
2. (Replay/Transition) In the second stage, we encounter the first write statement
  (`TYPE_WRITE`).  We switch to RWDB, where we replay the buffer
  along with the write statement.
3. (Read-write) In the third/final stage, all statements of any type (`TYPE_READ`,
  `TYPE_BUFFER`, `TYPE_WRITE`) are executed on RWDB.

## Consistency

civirpow provides *some* consistency, but it also has a limitation.

*Within a given MySQL session*, you can mix various read+write operations --
for example, insert a record, then read the record, then update it, and then read
it again.  Once you start writing, all requests are handled by RWDB -- which
should provide a fair degree of consistency.

However, there is one notable source of inconsistency: at the beginning of
the connection (before the first write), you'll read data from RODB (instead
of RWDB) -- so it may start with a dirty-read.  A few mitigating
considerations:

* If your environment regularly has a perceptible propagation delay between the RWDB+RODB (e.g.  30sec), then users
  may be sensitive to dirty-reads within the propagation period (e.g.  30sec).  Use an HTTP cookie or HTTP
  session-variable to force them onto RWDB (i.e. `forceWriteMode()`) for the subsequent 30-60 sec.  (TODO:
  Example code)

* Hopefully, some dirty-reads are acceptable (*or acceptably infrequent*). If dirty-reads are a total show-stopper, then
  replay-on-write may be the wrong approach for your use-case.

* If you know that a use-case will be writing and must have fresh reads, you can give a hint
  to force it into write mode; either
    * Call `$stateMachine->forceWriteMode()`, or...
    * (If you don't have access to `$stateMachine`) Issue a request for the dummy query
      `SELECT "civirpow-force-write"`.

