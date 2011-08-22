#!/bin/bash

cd `dirname $0`

rm -f framework.inc.php
sed "s/CLIENT/$CLIENT/g" LICENSE | sed "s/APPLICATION/$APP/g" > framework.inc.php
find ./library/ -type f -name \*.php | while read f
do
		php -w $f | grep -v '^<?php' >> framework.inc.php
done

SCRIPTS=`find ./static/js -depth -name \*.js | sort -r | xargs cat | php -r 'require "/srv/http/.common/jsminplus.php"; echo JSMinPlus::minify(file_get_contents("php://stdin"));'` 

echo "define('BASIC_JAVASCRIPT', <<<EOF
${SCRIPTS//\$/\\$}
EOF
);" >>  framework.inc.php
echo 'Basic::bootstrap();' >>  framework.inc.php

chmod -w framework.inc.php