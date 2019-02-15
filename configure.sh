#!/bin/sh

if [ -z "$1" ] || [ -z "$2" ] || [ -z "$3" ] ; then
  printf "Wrong parameters provided.\nUsage: sudo ./configure.sh <db-password> <yiimode:basic|advanced> <app-name>\n"
  exit
fi

export DB_PASSWD=$1
export YII_MODE=$2
export APPNAME=$3

# Add required repos
rpm --import https://debian.neo4j.org/neotechnology.gpg.key
cat <<EOF>  /etc/yum.repos.d/neo4j.repo
[neo4j]
name=Neo4j RPM Repository
baseurl=https://yum.neo4j.org/stable
enabled=1
gpgcheck=1
EOF

# Database configuration
dnf install neo4j-enterprise
curl -s https://api.github.com/repos/neo4j-graphql/neo4j-graphql/releases/latest | grep browser_download_url | cut -d '"' -f 4 | xargs wget -O /var/lib/neo4j/plugins/neo4j-graphql.jar
if ! grep -q "dbms.unmanaged_extension_classes=org.neo4j.graphql=/graphql" "/etc/neo4j/neo4j.conf"; then
	echo "dbms.unmanaged_extension_classes=org.neo4j.graphql=/graphql" >> /etc/neo4j/neo4j.conf
	echo "dbms.security.procedures.unrestricted=graphql.*" >> /etc/neo4j/neo4j.conf
fi
neo4j start
sleep 5
for i in {1..15}
do
	if (echo >/dev/tcp/localhost/7687) &>/dev/null && echo "Connection to database succeded!" || echo "\nAttempt $i to connect to database failed... retrying...\n" == "Connection to database succeded!"; then
		
		# Can continue execution

		echo "CALL dbms.security.changePassword('$DB_PASSWD');" | cypher-shell -u neo4j -p neo4j

		# Create Yii project
		sudo -u $SUDO_USER -H sh -c "composer create-project --prefer-dist yiisoft/yii2-app-$YII_MODE $APPNAME"

		# Create Yii DAL to GraphQL API
		sudo -u $SUDO_USER -H sh -c "cp params.php $APPNAME/config/params.php"
		sudo -u $SUDO_USER -H sh -c "sed -i -e 's/<db-password>/$DB_PASSWD/g' $APPNAME/config/params.php"
		sudo -u $SUDO_USER -H sh -c "mkdir $APPNAME/helpers"
		sudo -u $SUDO_USER -H sh -c "cp GraphDatabaseAccessLayer.php $APPNAME/helpers/GraphDatabaseAccessLayer.php"
		cd $APPNAME
		sudo -u $SUDO_USER -H sh -c "composer require guzzlehttp/guzzle"

		printf "\n\nConfiguration completed!\n\n"
		exit

	else
		sleep 1
	fi
done

# Database connection error
printf "Connection to database failed. Stopping!"
