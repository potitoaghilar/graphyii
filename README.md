# GraphYii
Use this stack to create powerful web application with Yii2 framework and a Neo4j graph database. This stack mixes many technologies like
 - Yii2 Framework
 - Neo4j Database
 - GraphQL
 - GraphiQL
 - GraphQL Editor

### Setup Neo4j
Install neo4j enterprise edition and configure it with a username and a password:
```
$ sudo dnf install neo4j-enterprise
```

Download the latest version of GraphQL plugin for Neo4j:
```
curl -s https://api.github.com/repos/neo4j-graphql/neo4j-graphql/releases/latest | grep browser_download_url | cut -d '"' -f 4 | xargs wget -O /var/lib/neo4j/plugins
```

Add this two lines in `/etc/neo4j.conf`
```
dbms.unmanaged_extension_classes=org.neo4j.graphql=/graphql
dbms.security.procedures.unrestricted=graphql.*
```

To start the server run:
```
$ sudo neo4j start
```

To set username and password for database login with default credentials `neo4j:neo4j` and execute this procedure:
```
CALL dbms.security.createUser(username, password, false)
```

### Setup Yii2
If you want a basic application with yii:
```
$ composer create-project --prefer-dist yiisoft/yii2-app-basic <app-name>
```

Or if you want an advanced setup:
```
$ composer create-project --prefer-dist yiisoft/yii2-app-advanced <app-name>
```

### GraphQL Editor
Install the editor with:
```
$ git clone https://github.com/slothking-online/graphql-editor
$ cd graphql-editor
$ npm i
$ npm run start
```

### GraphiQL
To easily create query and mutations you can use the tool provided here:

### Prepare Yii integration with
To prepare your environment stack execute this:
```
$ TODO
```