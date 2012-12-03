#!/usr/bin/env python

import sys

import config
import db

def exec_file(path=None):
    dbi = db.Connection(**config.db_params)
    return dbi.execute(
        open(path, "r"),
        params={ 'scratch': config.scratch_db_prefix }
    )

if __name__ == "__main__":
    results = exec_file(sys.argv[1])
    print "\t".join(results[0].keys())
    for row in results:
        print "\t".join([str(v) for v in row.values()])
