# Oro Database Behat Extension

### Installation

```
    composer require gorgo13/database-component
```

### Behat.yml

```
        Oro\BehatExtension\DatabaseBehatExtension\OroDatabaseBehatExtension:
            oro_legacy: ~ # if true then replaces ORO's behat database isolators with this one  
            doctrine_connections: ~ # List of doctrine connections to isolate default: "['default']"
            mysql:
                mysql: ~ # path to "mysql" binary, for example "/usr/bin/mysql", default: "mysql"
                mysqldump: ~ # path to "mysqldump" binary, for example "/usr/bin/mysqldump", default: "mysqldump"
            postgresql:
                createdb: ~ # path to "createdb" binary, for example "/usr/bin/createdb", default: "createdb"
                dropdb: ~ # path to "dropdb" binary, for example "/usr/bin/dropdb", default: "dropdb"
                psql: ~ # path to "psql" binary, for example "/usr/bin/psql", default: "psql"
```
