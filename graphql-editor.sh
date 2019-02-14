#!/bin/sh

export APPNAME="GraphQL Editor"

if [ -d "./graphql-editor" ]; then
    printf "\n$APPNAME - update..."
    cd graphql-editor
    git pull 1>/dev/null 2>/dev/null
    printf " Done!\n\n"
else
    printf "\n$APPNAME - download..."
    git clone https://github.com/slothking-online/graphql-editor 2>/dev/null
    cd graphql-editor
    printf " Done!\n\n"
fi

printf "$APPNAME - install dependecies..."
npm i  2>/dev/null
printf " Done!\n\n"
npm run start