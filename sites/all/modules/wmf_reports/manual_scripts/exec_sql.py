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

columns = [
    "first_name",
    "last_name",
    "organization_name",
    "phone",
    "email",
    "address",
    "supplemental_address",
    "city",
    "state",
    "country",
    "zip",
    "contributions",
    "note",
    "do_not_email",
    "do_not_phone",
    "civi_id",
]

if __name__ == "__main__":
    separator = ","
    results = exec_file(sys.argv[1])
    if not columns:
        columns = results[0].keys()
    print separator.join(columns)
    for row in results:
        print separator.join([str(row[key]) for key in columns])
