#!/bin/bash

cd `dirname $0`

rm -f framework.inc.php
sed "s/CLIENT/$CLIENT/g" LICENSE | sed "s/APPLICATION/$APP/g" > framework.inc.php
find ./library/ -type f -name \*.php | while read f
do
		php -w $f | grep -v '^<?php' >> framework.inc.php
done

echo 'Basic::bootstrap();' >>  framework.inc.php

chmod -w framework.inc.php