# GraphYii
Use this stack to create powerful web application with Yii2 framework and a Neo4j graph database. This stack mixes many technologies like
 - Yii2 (PHP Framework)
 - Neo4j (Database Solution)
 - GraphQL (Query Language for API)
 - GraphQL Playground (To test GQL queries)
 - GraphQL Editor (To build GQL schemas)

#### Dependecies
Install required dependencies:
```
$ sudo dnf install composer
$ sudo dnf install node
$ npm install electron -g
$ npm install yarn -g
```

## Automatic environment configuration
```
$ chmod +x configure.sh
$ sudo ./configure.sh  <db-password> <yiimode:basic|advanced> <app-name>
```

## Manual environment configuration

### Setup Neo4j
Add repository:
```
# Add required repos
rpm --import https://debian.neo4j.org/neotechnology.gpg.key
cat <<EOF>  /etc/yum.repos.d/neo4j.repo
[neo4j]
name=Neo4j RPM Repository
baseurl=https://yum.neo4j.org/stable
enabled=1
gpgcheck=1
EOF
```

Install neo4j enterprise edition and configure it with a username and a password:
```
$ sudo dnf install neo4j-enterprise
```

Download the latest version of GraphQL plugin for Neo4j:
```
$ sudo curl -s https://api.github.com/repos/neo4j-graphql/neo4j-graphql/releases/latest | grep browser_download_url | cut -d '"' -f 4 | xargs wget -O /var/lib/neo4j/plugins
```

Add extra configuration rules to `/etc/neo4j.conf`
```
$ sudo echo "dbms.unmanaged_extension_classes=org.neo4j.graphql=/graphql" >> /etc/neo4j.conf
$ sudo echo "dbms.security.procedures.unrestricted=graphql.*" >> /etc/neo4j.conf
```

To start the server run:
```
$ sudo neo4j start
```

Set new password for user neo4j:
```
$ echo "CALL dbms.security.changePassword('<new-password>');"  | cypher-shell -u neo4j -p neo4j
```

#### GraphQL schema managment
Create GraphQL schema from existing data in database
```
$ echo "CALL graphql.idl(null)"  | cypher-shell -u neo4j -p <new-password>
```

Create GraphQL schema from provided schema
```
$ echo "CALL graphql.idl('<my-schema>')"  | cypher-shell -u neo4j -p <new-password>
```
You can create a schema with GraphQL Editor tool

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
Run GraphQL Editor with these commands:
```
$ chmod +x graphql-editor.sh
$ ./graphql-editor.sh
```

### GraphQL Playground
To easily create query and mutations you can use GraphQL Playground:
```
$ chmod +x graphql-playground.sh
$ ./graphql-playground.sh
```

### Prepare Yii integration with GraphQL
To prepare your environment add these parameters to `<app-name>/config/params.php`:
```
return [

    [...]

    'api_endpoint' => 'http://localhost:7474/graphql/',
    'db_username' => "neo4j",
    'db_password' => "neo4j",
];
```

And execute this script:
```
$ mkdir <app-name>/helpers
$ cp GraphDatabaseAccessLayer.php <app-name>/helpers/GraphDatabaseAccessLayer.php
$ cd <app-name>
$ composer require guzzlehttp/guzzle
```

### Remove current .git project
Remove current .git directory to make space to production git project:
```
$ rm -rf .git
```

## Example
Load test data to database and implement this example class:
```

<?php

namespace app\controllers;

use Yii;
use yii\helpers\Json;
[...]

// Use the GraphDatabaseAccessLayer
use app\helpers\GraphDatabaseAccessLayer as GDAL;

class SiteController extends Controller {

    [...]

    public function actionMyGraph() {

        $result = [];

        // Fetch all movies title from graph database
        $movies = GDAL::query("{ Movie{ title } }")['data']['Movie'];

        // Adds random values to each film using title as key
        foreach($movies as $movie) {
            $result[$movie['title']] = Yii::$app->security->generateRandomString(5);
        }

    return Json::encode($result);

}

```