#!/bin/sh

if [ -n "$1"] || [ -n "$2"] || [ -n "$3"]  ; then
  printf "Wrong parameters provided.\nUsage: sudo ./configure.sh <db-password> <yiimode:basic|advanced> <app-name>\n"
  exit
fi

export DB_PASSWD=$1
export YII_MODE=$2
export APPNAME=$3

# Database configuration
dnf install neo4j-enterprise
curl -s https://api.github.com/repos/neo4j-graphql/neo4j-graphql/releases/latest | grep browser_download_url | cut -d '"' -f 4 | xargs wget -O /var/lib/neo4j/plugins
echo "dbms.unmanaged_extension_classes=org.neo4j.graphql=/graphql" >> /etc/neo4j.conf
echo "dbms.security.procedures.unrestricted=graphql.*" >> /etc/neo4j.conf
neo4j start
echo "CALL dbms.security.changePassword('$DB_PASSWD');"  | cypher-shell -u neo4j -p neo4j

# Create Yii project
composer create-project --prefer-dist yiisoft/yii2-app-$YII_MODE $APPNAME

# Create Yii DAL to GraphQL API
cp params.php $APPNAME/config/params.php
mkdir $APPNAME/helpers
cp GraphDatabaseAccessLayer.php $APPNAME/helpers/GraphDatabaseAccessLayer.php
cd $APPNAME
composer require guzzlehttp/guzzle
cd ..

# Remove .git directory
rm -rf .git