#!/bin/bash

cd `dirname $0`

rm -f framework.inc.php
sed "s/CLIENT/$CLIENT/g" LICENSE | sed "s/APPLICATION/$APP/g" > framework.inc.php
find ./library/ -type f -name \*.php | while read f
do
		php -w $f | grep -v '^<?php' >> framework.inc.php
done

# SCRIPTS=`php -r 'require "library/Basic.php"; require "library/Basic/Static.php"; echo Basic_Static::jsPopulateFiles(Basic_Static::findFiles("static/js", "js", false), true);'`

# echo "define('BASIC_JAVASCRIPT', <<<EOF
# ${SCRIPTS//\$/\\$}
# EOF
# );" >>  framework.inc.php

echo 'Basic::bootstrap();' >> framework.inc.php

chmod -w framework.inc.php