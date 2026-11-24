-- Disposable local development credentials; never use in production.
CREATE ROLE cms LOGIN PASSWORD 'local-only-cms';
CREATE ROLE cms_test LOGIN PASSWORD 'local-only-test';
CREATE DATABASE cms OWNER cms;
CREATE DATABASE cms_test OWNER cms_test;
REVOKE CONNECT ON DATABASE cms FROM PUBLIC;
REVOKE CONNECT ON DATABASE cms_test FROM PUBLIC;
GRANT CONNECT ON DATABASE cms TO cms;
GRANT CONNECT ON DATABASE cms_test TO cms_test;
