'''
Mysql wrapper

Features sql-file and multiple query execution (TODO: batch on the server),
and query composition.
'''
import MySQLdb as Dbi

class Connection(object):
    def __init__(self, host=None, user=None, passwd=None, db=None, debug=False):
        self.db_conn = Dbi.connect(host=host, user=user, passwd=passwd, db=db)
        self.cursor = self.db_conn.cursor(cursorclass=Dbi.cursors.DictCursor)
        self.debug = debug
        self.db_conn.set_character_set("utf8")

    def close(self):
        #self.db_conn.commit()
        pass

    def execute(self, sql, params=None):
        if hasattr(sql, 'read'):
            return self.execute(sql.read().split(";"), params=params)
        if hasattr(sql, 'sort'):
            for line in sql:
                line = line.strip()
                if not line:
                    continue
                result = self.execute(line.strip(), params=params)
            # return only the last line's results
            return result
        if not sql:
            return

        if params:
            sql = sql % params

        if self.debug:
            print sql
        self.cursor.execute(str(sql))
        return list(self.cursor.fetchall())

class Query(object):
    def __init__(self):
        self.columns = []
        self.tables = []
        self.where = []
        self.order_by = []
        self.group_by = []

    def __repr__(self):
        sql = "SELECT " + ",\n\t\t".join(self.columns)
        sql += "\n\tFROM " + "\n\t\t".join(self.tables)
        if self.where:
            sql += "\n\tWHERE " + "\n\t\tAND ".join(self.where)
        if self.order_by:
            sql += "\n\tORDER BY " + ", ".join(self.order_by)
        if self.group_by:
            sql += "\n\tGROUP BY " + ", ".join(self.group_by)
        return sql
