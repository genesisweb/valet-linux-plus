#!/bin/bash

set -e

SOURCE="${BASH_SOURCE[0]}"

if [[ -L $SOURCE ]]
then
    DIR="$( cd "$( dirname "$(readlink "$SOURCE")" )" && pwd )"
else
    DIR="$( cd "$( dirname "$SOURCE" )" && pwd )"
fi


if [[ ! -f "$DIR/cli/valet.php" ]]
then
    DIR="$DIR/../genesisweb/valet-linux-plus"
fi
FALLBACK_PHP="/usr/bin/php"
SELECTED_PHP=$(eval "/usr/bin/php $DIR/cli/valet.php which-php")

SELECTED_PHP=${SELECTED_PHP:-$FALLBACK_PHP}

eval "$SELECTED_PHP ${*}"
