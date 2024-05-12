#!/bin/bash

set -e

DEFAULT_PHP_BINARY="/usr/bin/php"
CONFIG_FILE="$HOME/.config/valet/config.json"

# Check if the JSON file exists
if [ -f "$CONFIG_FILE" ]; then
    IS_PWD_MATCHED=false

    # Read the paths array from the JSON file
    paths=$(jq -r '.paths | .[]' "$CONFIG_FILE")

    # Loop through the paths and print each one
    for path in $paths
    do
        if [[ "$PWD" == "$path"* ]]; then
            IS_PWD_MATCHED=true
            break
        fi
    done
    if [ $IS_PWD_MATCHED == true ]; then
        SITE_NAME=$( basename $PWD );
        SELECTED_PHP=$DEFAULT_PHP_BINARY
        if jq -e '.isolated_versions | length > 0' "$CONFIG_FILE" >/dev/null; then
            if [ "$(jq -r ".isolated_versions[\"$SITE_NAME\"]" "$CONFIG_FILE")" != "null" ]; then
                SELECTED_PHP=$(jq -r ".isolated_versions[\"$SITE_NAME\"]" "$CONFIG_FILE")
            elif [ "$(jq -r ".fallback_binary" "$CONFIG_FILE")" != "null" ]; then
                SELECTED_PHP=$(jq -r ".fallback_binary" "$CONFIG_FILE")
            fi
        fi
    else
        SELECTED_PHP=$(jq -r ".fallback_binary" "$CONFIG_FILE")
    fi
else
    SELECTED_PHP=$DEFAULT_PHP_BINARY
fi

if ! [ -f "$SELECTED_PHP" ]; then
    SELECTED_PHP=$DEFAULT_PHP_BINARY
fi

# shellcheck disable=SC2145
eval "$SELECTED_PHP ${@@Q}"
