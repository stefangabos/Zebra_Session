vendor/bin/phpunit --configuration phpunit.xml && vendor/bin/phpstan analyse && vendor/bin/phpcs --standard=coding-standards.xml
# To run test requiring a MySQL instance, copy phpunit.db-tests.xml.dist and run
# vendor/bin/phpunit --configuration phpunit.db-tests.xml