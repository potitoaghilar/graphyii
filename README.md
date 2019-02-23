# GraphYii

[![version](https://img.shields.io/badge/version-0.1%20alpha-orange.svg)](https://img.shields.io/badge/version-0.1%20alpha-orange.svg) [![Codacy Badge](https://api.codacy.com/project/badge/Grade/952fc83ca44f4cdc97c83323c73e5561)](https://app.codacy.com/app/aghilarn/graphyii?utm_source=github.com&utm_medium=referral&utm_content=potitoaghilar/graphyii&utm_campaign=Badge_Grade_Dashboard) [![HitCount](http://hits.dwyl.io/aghilarn/graphyii.svg)](http://hits.dwyl.io/aghilarn/graphyii) [![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

Use this stack to create powerful web application or any kind of API with Yii2 framework and a Neo4j graph database. This stack mixes many technologies like
 - Yii2 (PHP Framework)
 - Neo4j (Database Solution)
 - GraphQL (Query Language for API)
 - GraphQL Playground (To test GQL queries)
 - GraphQL Editor (To build GQL schemas)

## Example
Load test data to database and implement this example class:
```php

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

        // Build models with a provided GraphQL schema
        GDAL::buildSchemaModels('Models.graphql');

        // Entry point for our query
        $users = User::query([
            UserQueryAttributes::name => "Tom",
            // TODO this is not implemented yet
        ], function($data) {

            // Get only required data from database and return in format you want

            $result = [];

            /**
             * @var $data User[]
             */
            foreach ($data as $user) {
                foreach ($user->answers() as $answer) {
                    $result[$answer->id()] = [
                        'text' => $answer->text(),
                        'score' => $answer->score(),
                        'author' => $user->name(),
                        'author_username' => $user->screen_name(),
                    ];
                }
            }

            return $result;

            // IMPORTANT: USE THE CALLBACK ONLY TO FETCH DATA - DO NOT INCLUDE ANY OTHER LOGIC HERE!

        });

        // The GDAL will analyze and fetch only required data from database

        // Return result in Json format
        return Json::encode($users);

        // Less code than you thought, ah?

    }

}

```

#### Dependecies
Install required dependencies:
```sh
$ sudo dnf install composer
$ sudo dnf install node
$ npm install electron -g
$ npm install yarn -g
```

## Automatic environment configuration
```sh
$ chmod +x configure.sh
$ sudo ./configure.sh  <db-password> <yiimode:basic|advanced> <app-name>
```
TODO: implement for advanced yii template

## Manual environment configuration

### Setup Neo4j
Add repository:
```sh
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
```sh
$ sudo dnf install neo4j-enterprise
```

Download the latest version of GraphQL plugin for Neo4j:
```sh
$ sudo curl -s https://api.github.com/repos/neo4j-graphql/neo4j-graphql/releases/latest | grep browser_download_url | cut -d '"' -f 4 | xargs wget -O /var/lib/neo4j/plugins
```

Add extra configuration rules to `/etc/neo4j/neo4j.conf`
```sh
$ sudo echo "dbms.unmanaged_extension_classes=org.neo4j.graphql=/graphql" >> /etc/neo4j/neo4j.conf
$ sudo echo "dbms.security.procedures.unrestricted=graphql.*" >> /etc/neo4j/neo4j.conf
```

To start the server run:
```sh
$ sudo neo4j start
```

Set new password for user neo4j:
```sh
$ echo "CALL dbms.security.changePassword('<new-password>');"  | cypher-shell -u neo4j -p neo4j
```

#### GraphQL schema managment
Create GraphQL schema from existing data in database
```sh
$ echo "CALL graphql.idl(null)"  | cypher-shell -u neo4j -p <new-password>
```

Create GraphQL schema from provided schema
```sh
$ echo "CALL graphql.idl('<my-schema>')"  | cypher-shell -u neo4j -p <new-password>
```
You can create a schema with GraphQL Editor tool

### Setup Yii2
If you want a basic application with yii:
```sh
$ composer create-project --prefer-dist yiisoft/yii2-app-basic <app-name>
```

Or if you want an advanced setup:
```sh
$ composer create-project --prefer-dist yiisoft/yii2-app-advanced <app-name>
```

### GraphQL Editor
Run GraphQL Editor with these commands:
```sh
$ chmod +x graphql-editor.sh
$ ./graphql-editor.sh
```

### GraphQL Playground
To easily create query and mutations you can use GraphQL Playground:
```sh
$ chmod +x graphql-playground.sh
$ ./graphql-playground.sh
```

### Prepare Yii integration with GraphQL
To prepare your environment add these parameters to `<app-name>/config/params.php`:
```sh
return [

    [...]

    'api_endpoint' => 'http://localhost:7474/graphql/',
    'db_username' => "neo4j",
    'db_password' => "<db-password>",
];
```

And execute this script:
```sh
$ mkdir <app-name>/helpers
$ cp GraphDatabaseAccessLayer.php <app-name>/helpers/GraphDatabaseAccessLayer.php
$ cp GraphModelType.php <app-name>/helpers/GraphModelType.php
$ cp GraphDatabaseAccessLayerException.php <app-name>/helpers/GraphDatabaseAccessLayerException.php
$ cd <app-name>
$ touch <app-name>/models/Models.graphql
$ composer require guzzlehttp/guzzle graphaware/neo4j-php-client:^4.0
```

## Contributions
Any contribution will be apreciated! Just send a PR.