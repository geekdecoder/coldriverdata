# Encrypting the database at rest (MariaDB)

Chat text lives in the database, and search needs to read it, so it is protected by having MariaDB
encrypt this site's tables on disk. Only this site's tables are changed; other databases on the
server are left as they are.

Run these as root. Take a database backup first (`php bin/backup.php`).

## 1. Create the key

    mkdir -p /etc/mysql/encryption
    (echo -n "1;"; openssl rand -hex 32) > /etc/mysql/encryption/keyfile
    chown -R mysql:mysql /etc/mysql/encryption
    chmod 500 /etc/mysql/encryption
    chmod 400 /etc/mysql/encryption/keyfile

**Copy the contents of that keyfile somewhere safe that is not this server** (a password manager).
If the key is lost, the encrypted tables cannot be read by anyone.

## 2. Load the key plugin

Create `/etc/mysql/mariadb.conf.d/60-encryption.cnf` (on some systems `/etc/my.cnf.d/encryption.cnf`):

    [mariadb]
    plugin_load_add = file_key_management
    file_key_management_filename = /etc/mysql/encryption/keyfile
    file_key_management_encryption_algorithm = AES_CTR
    innodb_encrypt_log = ON
    encrypt_tmp_files = ON

Then restart the database and confirm the plugin is active:

    systemctl restart mariadb
    mysql -e "SELECT PLUGIN_NAME, PLUGIN_STATUS FROM information_schema.PLUGINS WHERE PLUGIN_NAME='file_key_management'"

If MariaDB does not start, remove the `.cnf` file, start it again, and check the key file's path
and permissions.

## 3. Encrypt this site's tables

    cd /home/coldriverdata.net/app
    sudo -u coldr4180 php bin/encrypt-db-tables.php

Admin > Protection then shows the database as encrypted.

Run `bin/encrypt-db-tables.php` again after any update that adds tables (after `bin/migrate.php`).

## What this does and does not protect

It protects the data files on disk, and copies of them, from being read without the key: a stolen
or discarded disk, or a copy of the raw database files. Because the key file is on the same server,
it does not protect against someone who gains full control of the running server, and a full-server
backup or snapshot contains both the data and the key.
