#!/bin/sh

export APPNAME="GraphQL Playground"

if [ -d "./graphql-playground" ]; then
    printf "\n$APPNAME - update..."
    cd graphql-playground
    git pull &>/dev/null
    printf " Done!\n\n"
else
    printf "\n$APPNAME - download..."
    git clone https://github.com/prisma/graphql-playground.git &>/dev/null
    cd graphql-playground
    printf " Done!\n\n"
fi

printf "$APPNAME - install dependecies...\n"
cd packages/graphql-playground-electron
yarn install
printf "Done!\n\n"
yarn start

printf("Remeber to set graphql API and authorization header:\nDefault endpoint: http://localhost:7474/graphql/\nDefault HTTP auth header: {\"Authorization\": \"Basic <base64(username:password)>\"}")