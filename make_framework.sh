#!/bin/bash

cd `dirname $0`

# use tempfile to prevent renewing mtime
F=`mktemp`
echo -e '<?php\n/**\n' >$F
sed "s/CLIENT/$CLIENT/g" LICENSE | sed "s/APPLICATION/$APP/g" >> $F
echo -e '\n*/\n' >>$F

find ./library/ -type f -name \*.php | while read f
do
	php -w $f | grep -v '^<?php' >> $F
done

echo 'Basic::bootstrap();' >> $F

# for rsync; make mtime equal to last actual modification, not now
touch -d "`du -s --time .|cut -f2`" $F
mv -f $F framework.inc.php

chmod -w,+r framework.inc.php