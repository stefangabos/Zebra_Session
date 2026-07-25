# for running the tests a MySQL instance is needed
# copy phpunit.xml.dist to phpunit.xml (git-ignored) and put your connection details there
# phpunit uses phpunit.xml when it exists and falls back to phpunit.xml.dist otherwise
vendor/bin/phpunit && echo && vendor/bin/phpstan analyse && echo && vendor/bin/phpcs --standard=coding-standards.xml
