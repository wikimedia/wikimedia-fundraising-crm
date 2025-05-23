The System tools extension provides an api for parsing redis monitoring output.

Output can be captured on the command line like

`redis-cli monitor > redis.log`

This output can be processed with

cv api4 Redislog.parse version=4 fileName=/path/redis.log


This command does 2 things

- provides some summary data

- outputs '`' (backtick) separated file which can be imported into mysql (backtick seems to be otherwise not present so usable but we could make the separator a parameter)
