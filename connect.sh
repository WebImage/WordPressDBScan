#!/bin/bash

DIR=$(dirname $0)
HOST=$1

if [ -f ".dbhost" ] && [ "$HOST" = "" ]; then
	HOST=$(cat .dbhost)
fi

if [ "$HOST" = "" ]; then
	echo "USAGE: $0 [dbhost]"
	exit 1;
fi

if [ ! -f wp-config.php ]; then
	echo "Run from wp-config.php directory"
	exit 1;
fi

echo "Running db-cli.php dbhost=$HOST mycnf and dumping to my.cnf"
COMMAND=$(php $DIR/db-cli.php dbhost=$HOST mycnf > "my.cnf")
echo "Connecting to $HOST"
mysql --defaults-file=my.cnf
