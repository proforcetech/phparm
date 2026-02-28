#!/bin/bash

# Ensure Bash is used even if invoked via `sh deploy.sh`.
if [ -z "${BASH_VERSION:-}" ]; then
    exec /usr/bin/env bash "$0" "$@"
fi

#===============================================================================
# PHPArm Full Server Deployment Script
#===============================================================================
#
# This script performs a complete deployment of PHPArm on a fresh server.
# Designed for Ubuntu 22.04/24.04 LTS on Linode, DigitalOcean, or similar VPS.
#
# Features:
#   - Installs all dependencies (Apache OR Nginx, PHP 8.4, MariaDB, Node.js, Composer)
#   - Configures chosen web server with security headers
#   - Sets up MariaDB with secure installation
#   - Configures UFW firewall
#   - Optional SSL via Let's Encrypt
#   - Creates admin user with random password
#   - Sets appropriate file permissions
#
# Usage:
#   chmod +x deploy.sh
#   sudo ./deploy.sh
#
# Options:
#   --webserver=apache|nginx  Choose webserver (default: apache)
#   --domain=example.com      Set the domain name
#   --email=admin@example.com Admin email for SSL certificates
#   --skip-ssl                Skip SSL certificate installation
#   --skip-firewall           Skip firewall configuration
#   --branch=main             Git branch to deploy (default: main)
#   --repo=URL                Git repository URL
#
#===============================================================================

set -e  # Exit on error

# ============================================
# Configuration Variables
# ============================================

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

# Default values
INSTALL_DIR="/var/www/phparm"
WEB_USER="www-data"
WEB_GROUP="www-data"
NODE_VERSION="20"
DOMAIN=""
ADMIN_EMAIL=""
SKIP_SSL=false
SKIP_FIREWALL=false
GIT_BRANCH="main"
GIT_REPO=""
WEBSERVER="apache"   # apache|nginx

# Generated values (runtime)
DB_NAME=""
DB_USER=""
DB_PASS=""
ADMIN_PASSWORD=""
JWT_SECRET=""

# ============================================
# Helper Functions
# ============================================

print_header() {
    echo -e "\n${BOLD}${CYAN}════════════════════════════════════════════${NC}"
    echo -e "${BOLD}${CYAN} $1${NC}"
    echo -e "${BOLD}${CYAN}════════════════════════════════════════════${NC}\n"
}

print_status() { echo -e "${GREEN}✓${NC} $1"; }
print_warning() { echo -e "${YELLOW}⚠${NC} $1"; }
print_error() { echo -e "${RED}✗${NC} $1"; }
print_info() { echo -e "${CYAN}→${NC} $1"; }

generate_password() {
    local length=${1:-16}
    openssl rand -base64 48 | tr -dc 'a-zA-Z0-9!@#$%^&*' | head -c "$length"
}

generate_secret() {
    local length=${1:-64}
    local secret=""
    while [[ ${#secret} -lt $length ]]; do
        secret+=$(openssl rand -base64 96 | tr -dc 'A-Za-z0-9')
    done
    printf '%s' "${secret:0:$length}"
}

# Generate safe MariaDB identifiers (db name <=64 chars, user <=32 chars)
generate_db_identifiers() {
    local suffix
    suffix="$(openssl rand -hex 3)" # 6 chars
    DB_NAME="phparm_${suffix}"
    DB_USER="phparm_u_${suffix}"
    DB_NAME="${DB_NAME:0:64}"
    DB_USER="${DB_USER:0:32}"
}

restart_webserver() {
    if [[ "$WEBSERVER" == "nginx" ]]; then
        systemctl restart nginx
    else
        systemctl restart apache2
    fi
}

reload_webserver() {
    if [[ "$WEBSERVER" == "nginx" ]]; then
        systemctl reload nginx
    else
        systemctl reload apache2
    fi
}

check_root() {
    if [[ $EUID -ne 0 ]]; then
        print_error "This script must be run as root (use sudo)"
        exit 1
    fi
}

check_os() {
    if [[ ! -f /etc/os-release ]]; then
        print_error "Cannot detect OS. This script requires Ubuntu 22.04 or 24.04"
        exit 1
    fi

    source /etc/os-release

    if [[ "$ID" != "ubuntu" ]]; then
        print_error "This script is designed for Ubuntu. Detected: $ID"
        exit 1
    fi

    if [[ "$VERSION_ID" != "22.04" && "$VERSION_ID" != "24.04" ]]; then
        print_warning "Tested on Ubuntu 22.04 and 24.04. You have: $VERSION_ID"
    fi
}

validate_webserver_choice() {
    if [[ "$WEBSERVER" != "apache" && "$WEBSERVER" != "nginx" ]]; then
        print_error "Invalid --webserver value: ${WEBSERVER}. Use apache or nginx."
        exit 1
    fi
}

# ============================================
# Parse Command Line Arguments
# ============================================

parse_arguments() {
    for arg in "$@"; do
        case $arg in
            --webserver=*)
                WEBSERVER="${arg#*=}"
                ;;
            --domain=*)
                DOMAIN="${arg#*=}"
                ;;
            --email=*)
                ADMIN_EMAIL="${arg#*=}"
                ;;
            --skip-ssl)
                SKIP_SSL=true
                ;;
            --skip-firewall)
                SKIP_FIREWALL=true
                ;;
            --branch=*)
                GIT_BRANCH="${arg#*=}"
                ;;
            --repo=*)
                GIT_REPO="${arg#*=}"
                ;;
            --help)
                show_help
                exit 0
                ;;
            *)
                print_warning "Unknown argument: $arg"
                ;;
        esac
    done
}

show_help() {
    echo "PHPArm Deployment Script"
    echo ""
    echo "Usage: sudo ./deploy.sh [options]"
    echo ""
    echo "Options:"
    echo "  --webserver=apache|nginx Choose webserver (default: apache)"
    echo "  --domain=DOMAIN          Set the domain name (e.g., phparm.example.com)"
    echo "  --email=EMAIL            Admin email for SSL certificates"
    echo "  --skip-ssl               Skip Let's Encrypt SSL installation"
    echo "  --skip-firewall          Skip UFW firewall configuration"
    echo "  --branch=BRANCH          Git branch to deploy (default: main)"
    echo "  --repo=URL               Git repository URL to clone"
    echo "  --help                   Show this help message"
}

# ============================================
# Interactive Prompts
# ============================================

interactive_setup() {
    print_header "Interactive Setup"

    if [[ -z "$WEBSERVER" ]]; then
        WEBSERVER="apache"
    fi

    if [[ -z "$DOMAIN" ]]; then
        read -p "Enter domain name (or press Enter for IP-based access): " DOMAIN
    fi

    if [[ -z "$ADMIN_EMAIL" ]]; then
        read -p "Enter admin email address: " ADMIN_EMAIL
    fi

    if [[ -n "$DOMAIN" && "$SKIP_SSL" == false ]]; then
        read -p "Install SSL certificate via Let's Encrypt? [Y/n]: " ssl_choice
        if [[ "$ssl_choice" =~ ^[Nn]$ ]]; then
            SKIP_SSL=true
        fi
    fi

    if [[ -z "$GIT_REPO" ]]; then
        read -p "Git repository URL (or press Enter to skip git clone): " GIT_REPO
    fi
}

# ============================================
# System Updates
# ============================================

update_system() {
    print_header "Updating System Packages"

    apt-get update -y
    apt-get upgrade -y
    apt-get install -y software-properties-common curl wget gnupg2 ca-certificates lsb-release apt-transport-https

    print_status "System packages updated"
}

# ============================================
# Install PHP 8.4 (and webserver-specific PHP integration)
# ============================================

install_php() {
    print_header "Installing PHP 8.4"

    add-apt-repository -y ppa:ondrej/php
    apt-get update -y

    # Remove any older PHP packages if present (best-effort)
    apt-get purge -y 'php7.*' 'php8.0*' 'php8.1*' 'php8.2*' 'php8.3*' 2>/dev/null || true
    apt-get autoremove -y 2>/dev/null || true

    if [[ "$WEBSERVER" == "nginx" ]]; then
        # Nginx uses PHP-FPM
        apt-get install -y \
            php8.4 \
            php8.4-fpm \
            php8.4-cli \
            php8.4-common \
            php8.4-mysql \
            php8.4-pdo \
            php8.4-mbstring \
            php8.4-xml \
            php8.4-curl \
            php8.4-zip \
            php8.4-gd \
            php8.4-bcmath \
            php8.4-intl \
            php8.4-opcache \
            php8.4-readline

        systemctl enable php8.4-fpm
        systemctl start php8.4-fpm

        PHP_INI="/etc/php/8.4/fpm/php.ini"
    else
        # Apache uses mod_php here (keeps your existing behavior)
        apt-get install -y \
            php8.4 \
            php8.4-cli \
            php8.4-common \
            php8.4-mysql \
            php8.4-pdo \
            php8.4-mbstring \
            php8.4-xml \
            php8.4-curl \
            php8.4-zip \
            php8.4-gd \
            php8.4-bcmath \
            php8.4-intl \
            php8.4-opcache \
            php8.4-readline \
            libapache2-mod-php8.4

        a2enmod php8.4 || true
        PHP_INI="/etc/php/8.4/apache2/php.ini"
    fi

    # Configure PHP INI
    sed -i 's/upload_max_filesize = .*/upload_max_filesize = 64M/' "$PHP_INI"
    sed -i 's/post_max_size = .*/post_max_size = 64M/' "$PHP_INI"
    sed -i 's/memory_limit = .*/memory_limit = 256M/' "$PHP_INI"
    sed -i 's/max_execution_time = .*/max_execution_time = 300/' "$PHP_INI"
    sed -i 's/;date.timezone =.*/date.timezone = UTC/' "$PHP_INI"
    sed -i 's/expose_php = .*/expose_php = Off/' "$PHP_INI"

    cat > /etc/php/8.4/mods-available/opcache-custom.ini << 'EOF'
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.save_comments=1
EOF

    if [[ "$WEBSERVER" == "nginx" ]]; then
        systemctl restart php8.4-fpm
    fi

    php -v | head -n 1
    print_status "PHP 8.4 installed and configured"
}

# ============================================
# Install Apache (optional)
# ============================================

install_apache() {
    print_header "Installing Apache Web Server"

    apt-get install -y apache2

    a2enmod rewrite
    a2enmod headers
    a2enmod ssl
    a2enmod expires
    a2enmod deflate

    a2dissite 000-default.conf 2>/dev/null || true

    cat > /etc/apache2/conf-available/security-hardening.conf << 'EOF'
# Security Headers
ServerTokens Prod
ServerSignature Off
TraceEnable Off

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
EOF

    a2enconf security-hardening

    systemctl enable apache2
    systemctl start apache2

    print_status "Apache installed and configured"
}

# ============================================
# Install Nginx (optional)
# ============================================

install_nginx() {
    print_header "Installing Nginx Web Server"

    apt-get install -y nginx

    # Basic hardening defaults
    cat > /etc/nginx/snippets/phparm-security.conf << 'EOF'
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
EOF

    systemctl enable nginx
    systemctl start nginx

    print_status "Nginx installed and configured"
}

# ============================================
# Configure Apache Virtual Host
# ============================================

configure_apache_vhost() {
    print_header "Configuring Apache Virtual Host"

    SERVER_NAME="${DOMAIN:-$(hostname -I | awk '{print $1}')}"

    cat > /etc/apache2/sites-available/phparm.conf << EOF
<VirtualHost *:80>
    ServerName ${SERVER_NAME}
    ServerAdmin ${ADMIN_EMAIL:-webmaster@localhost}
    DocumentRoot ${INSTALL_DIR}/public

    <Directory ${INSTALL_DIR}/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    DirectoryIndex index.php index.html

    <FilesMatch \.php$>
        SetHandler application/x-httpd-php
    </FilesMatch>

    # Logging
    ErrorLog \${APACHE_LOG_DIR}/phparm_error.log
    CustomLog \${APACHE_LOG_DIR}/phparm_access.log combined

    # Security headers
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"

    # Compression
    <IfModule mod_deflate.c>
        AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css application/javascript application/json
    </IfModule>

    # Cache static assets
    <IfModule mod_expires.c>
        ExpiresActive On
        ExpiresByType image/jpg "access plus 1 year"
        ExpiresByType image/jpeg "access plus 1 year"
        ExpiresByType image/png "access plus 1 year"
        ExpiresByType image/gif "access plus 1 year"
        ExpiresByType image/svg+xml "access plus 1 year"
        ExpiresByType text/css "access plus 1 month"
        ExpiresByType application/javascript "access plus 1 month"
    </IfModule>
</VirtualHost>
EOF

    a2ensite phparm.conf
    systemctl reload apache2

    print_status "Apache virtual host configured"
}

# ============================================
# Configure Nginx Site
# ============================================

configure_nginx_site() {
    print_header "Configuring Nginx Site"

    SERVER_NAME="${DOMAIN:-_}" # nginx "_" wildcard if no domain provided

    # Remove default site
    rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true

    cat > /etc/nginx/sites-available/phparm << EOF
server {
    listen 80;
    server_name ${SERVER_NAME};

    root ${INSTALL_DIR}/public;
    index index.php index.html;

    include /etc/nginx/snippets/phparm-security.conf;

    access_log /var/log/nginx/phparm_access.log;
    error_log  /var/log/nginx/phparm_error.log;

    # Serve static files directly
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # PHP via PHP-FPM (8.4)
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to hidden files (except .well-known for certbot)
    location ~ /\.(?!well-known) {
        deny all;
    }

    # Cache common static assets
    location ~* \.(?:css|js|jpg|jpeg|png|gif|svg|ico|woff2?)\$ {
        expires 30d;
        add_header Cache-Control "public, max-age=2592000";
        try_files \$uri =404;
    }
}
EOF

    ln -sf /etc/nginx/sites-available/phparm /etc/nginx/sites-enabled/phparm

    nginx -t
    systemctl reload nginx

    print_status "Nginx site configured"
}

# ============================================
# Install MariaDB
# ============================================

install_mariadb() {
    print_header "Installing MariaDB"

    apt-get install -y mariadb-server mariadb-client

    systemctl enable mariadb
    systemctl start mariadb

    DB_PASS=$(generate_password 24)

    mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_PASS}';"
    mysql -u root -p"${DB_PASS}" << EOF
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
EOF

    mysql -u root -p"${DB_PASS}" << EOF
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF

    print_status "MariaDB installed and configured"
    print_info "Database: ${DB_NAME}"
    print_info "Username: ${DB_USER}"
}

# ============================================
# Install Node.js
# ============================================

install_nodejs() {
    print_header "Installing Node.js ${NODE_VERSION}"

    curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash -
    apt-get install -y nodejs

    node_version=$(node -v)
    npm_version=$(npm -v)

    print_status "Node.js ${node_version} installed"
    print_status "npm ${npm_version} installed"
}

# ============================================
# Install Composer
# ============================================

install_composer() {
    print_header "Installing Composer"

    EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

    if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then
        print_error "Composer installer signature mismatch"
        rm composer-setup.php
        exit 1
    fi

    php composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm composer-setup.php

    composer_version=$(composer --version)
    print_status "Composer installed: ${composer_version}"
}

# ============================================
# Install Additional Tools
# ============================================

install_tools() {
    print_header "Installing Additional Tools"

    apt-get install -y \
        git \
        unzip \
        acl \
        supervisor \
        cron

    print_status "Additional tools installed"
}

# ============================================
# Deploy Application
# ============================================

deploy_application() {
    print_header "Deploying PHPArm Application"

    mkdir -p "$INSTALL_DIR"

    if [[ -n "$GIT_REPO" ]]; then
        print_info "Cloning from ${GIT_REPO}..."
        git clone --branch "$GIT_BRANCH" "$GIT_REPO" "$INSTALL_DIR"
    else
        if [[ -f "./composer.json" ]]; then
            print_info "Copying files from current directory..."
            cp -r ./* "$INSTALL_DIR/"
            cp -r ./.env.example "$INSTALL_DIR/" 2>/dev/null || true
            cp -r ./.gitignore "$INSTALL_DIR/" 2>/dev/null || true
        else
            print_warning "No git repo specified and no local files found."
            print_info "Please manually copy your application files to ${INSTALL_DIR}"
        fi
    fi

    cd "$INSTALL_DIR"

    if [[ -f "composer.json" ]]; then
        print_info "Installing PHP dependencies..."
        composer install --no-dev --optimize-autoloader --no-interaction
        print_status "PHP dependencies installed"
    fi

    if [[ -f "package.json" ]]; then
        print_info "Installing Node.js dependencies..."
        npm ci --production=false
        print_status "Node.js dependencies installed"

        print_info "Building frontend assets..."
        rm -rf "${INSTALL_DIR}/public/assets" "${INSTALL_DIR}/public/index.html" "${INSTALL_DIR}/public/.vite"
        npm run build
        print_status "Frontend assets built"
    fi

    print_status "Application deployed to ${INSTALL_DIR}"
}

# ============================================
# Configure Environment
# ============================================

configure_environment() {
    print_header "Configuring Environment"

    cd "$INSTALL_DIR"

    JWT_SECRET=$(generate_secret)
    ADMIN_PASSWORD=$(generate_password 16)

    if [[ -f ".env.example" ]]; then
        cp .env.example .env

        sed -i "s|APP_ENV=.*|APP_ENV=production|" .env
        sed -i "s|APP_DEBUG=.*|APP_DEBUG=false|" .env
        sed -i "s|APP_URL=.*|APP_URL=https://${DOMAIN:-localhost}|" .env

        sed -i "s|DB_HOST=.*|DB_HOST=localhost|" .env
        sed -i "s|DB_PORT=.*|DB_PORT=3306|" .env
        sed -i "s|DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" .env
        sed -i "s|DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" .env
        sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" .env

        sed -i "s|JWT_SECRET=.*|JWT_SECRET=${JWT_SECRET}|" .env

        chmod 600 .env
        print_status ".env file configured"
    else
        print_error ".env.example not found!"
    fi
}

# ============================================
# Install Database Schema
# ============================================

install_database() {
    print_header "Installing Database Schema"

    cd "$INSTALL_DIR"

    if [[ -f "install_db.php" ]]; then
        print_info "Running database installation via install_db.php..."
        php install_db.php --force
        print_status "Database schema installed"
    else
        print_warning "install_db.php not found. Database must be installed manually."
    fi
}

# ============================================
# Create Admin User
# ============================================

create_admin_user() {
    print_header "Creating Admin User"

    cd "$INSTALL_DIR"

    HASHED_PASSWORD=$(php -r "echo password_hash('${ADMIN_PASSWORD}', PASSWORD_BCRYPT, ['cost' => 12]);")

    mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" << EOF
INSERT INTO users (name, email, password, role, active, email_verified, created_at, updated_at)
VALUES ('Administrator', '${ADMIN_EMAIL:-admin@localhost}', '${HASHED_PASSWORD}', 'admin', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    password = '${HASHED_PASSWORD}',
    role = 'admin',
    active = 1,
    updated_at = NOW();
EOF

    print_status "Admin user created"

    cat > /var/www/.user << EOF
PHPArm Admin Credentials
========================
Generated: $(date)

URL: https://${DOMAIN:-localhost}
Email: ${ADMIN_EMAIL:-admin@localhost}
Password: ${ADMIN_PASSWORD}

Webserver: ${WEBSERVER}

Database:
  Host: localhost
  Name: ${DB_NAME}
  User: ${DB_USER}
  Password: ${DB_PASS}
EOF

    chmod 600 /var/www/.user
    print_status "Credentials saved to /var/www/.user"
}

# ============================================
# Set File Permissions
# ============================================

set_permissions() {
    print_header "Setting File Permissions"

    cd "$INSTALL_DIR"

    chown -R "$WEB_USER:$WEB_GROUP" "$INSTALL_DIR"
    find "$INSTALL_DIR" -type d -exec chmod 755 {} \;
    find "$INSTALL_DIR" -type f -exec chmod 644 {} \;

    chmod +x "$INSTALL_DIR"/*.php 2>/dev/null || true
    chmod +x "$INSTALL_DIR"/*.sh 2>/dev/null || true

    chmod 600 "$INSTALL_DIR/.env" 2>/dev/null || true

    mkdir -p "$INSTALL_DIR/storage/logs" "$INSTALL_DIR/storage/uploads" "$INSTALL_DIR/storage/cache"
    chmod -R 775 "$INSTALL_DIR/storage"
    chown -R "$WEB_USER:$WEB_GROUP" "$INSTALL_DIR/storage"

    setfacl -R -m u:${WEB_USER}:rwX "$INSTALL_DIR/storage" 2>/dev/null || true
    setfacl -R -d -m u:${WEB_USER}:rwX "$INSTALL_DIR/storage" 2>/dev/null || true

    print_status "File permissions configured"
}

# ============================================
# Configure Firewall
# ============================================

configure_firewall() {
    if [[ "$SKIP_FIREWALL" == true ]]; then
        print_warning "Skipping firewall configuration"
        return
    fi

    print_header "Configuring UFW Firewall"

    apt-get install -y ufw
    ufw --force reset
    ufw default deny incoming
    ufw default allow outgoing
    ufw allow ssh
    ufw allow 80/tcp
    ufw allow 443/tcp

    echo "y" | ufw enable

    print_status "Firewall configured"
    ufw status verbose
}

# ============================================
# Install SSL Certificate (Apache or Nginx)
# ============================================

install_ssl() {
    if [[ "$SKIP_SSL" == true ]] || [[ -z "$DOMAIN" ]]; then
        print_warning "Skipping SSL installation"
        return
    fi

    print_header "Installing SSL Certificate"

    apt-get install -y certbot

    if [[ "$WEBSERVER" == "nginx" ]]; then
        apt-get install -y python3-certbot-nginx

        certbot --nginx \
            --non-interactive \
            --agree-tos \
            --email "${ADMIN_EMAIL:-admin@${DOMAIN}}" \
            -d "$DOMAIN" \
            --redirect
    else
        apt-get install -y python3-certbot-apache

        certbot --apache \
            --non-interactive \
            --agree-tos \
            --email "${ADMIN_EMAIL:-admin@${DOMAIN}}" \
            -d "$DOMAIN" \
            --redirect
    fi

    systemctl enable certbot.timer
    systemctl start certbot.timer

    print_status "SSL certificate installed for ${DOMAIN}"
}

# ============================================
# Configure Log Rotation
# ============================================

configure_logrotate() {
    print_header "Configuring Log Rotation"

    cat > /etc/logrotate.d/phparm << EOF
${INSTALL_DIR}/storage/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 ${WEB_USER} ${WEB_GROUP}
    sharedscripts
}
EOF

    print_status "Log rotation configured"
}

# ============================================
# Setup Cron Jobs
# ============================================

setup_cron() {
    print_header "Setting Up Scheduled Tasks"

    if [[ -f "${INSTALL_DIR}/cron.php" ]]; then
        (crontab -l 2>/dev/null; echo "* * * * * cd ${INSTALL_DIR} && php cron.php >> /dev/null 2>&1") | crontab -
        print_status "Cron job added for application scheduler"
    fi

    cat > /etc/cron.d/phparm-backup << EOF
0 2 * * * root mysqldump -u ${DB_USER} -p'${DB_PASS}' ${DB_NAME} | gzip > /var/backups/phparm_\$(date +\%Y\%m\%d).sql.gz
EOF

    chmod 644 /etc/cron.d/phparm-backup
    mkdir -p /var/backups

    print_status "Scheduled tasks configured"
}

# ============================================
# Webserver Install/Config Switch
# ============================================

install_webserver() {
    if [[ "$WEBSERVER" == "nginx" ]]; then
        # If apache is present, disable it to avoid port conflicts (best-effort)
        systemctl stop apache2 2>/dev/null || true
        systemctl disable apache2 2>/dev/null || true

        install_nginx
    else
        # If nginx is present, disable it to avoid port conflicts (best-effort)
        systemctl stop nginx 2>/dev/null || true
        systemctl disable nginx 2>/dev/null || true

        install_apache
    fi
}

configure_webserver() {
    if [[ "$WEBSERVER" == "nginx" ]]; then
        configure_nginx_site
    else
        configure_apache_vhost
    fi
}

# ============================================
# Final Summary
# ============================================

print_summary() {
    print_header "Installation Complete!"

    echo -e "${GREEN}"
    echo "╔═══════════════════════════════════════════════════════════╗"
    echo "║                                                           ║"
    echo "║   PHPArm has been successfully deployed!                  ║"
    echo "║                                                           ║"
    echo "╚═══════════════════════════════════════════════════════════╝"
    echo -e "${NC}"

    echo ""
    echo -e "${BOLD}Access Details:${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    if [[ -n "$DOMAIN" ]] && [[ "$SKIP_SSL" == false ]]; then
        echo -e "  URL:           ${CYAN}https://${DOMAIN}${NC}"
    else
        echo -e "  URL:           ${CYAN}http://${DOMAIN:-$(hostname -I | awk '{print $1}')}${NC}"
    fi

    echo -e "  Webserver:     ${CYAN}${WEBSERVER}${NC}"
    echo -e "  Admin Email:   ${CYAN}${ADMIN_EMAIL:-admin@localhost}${NC}"
    echo -e "  Admin Password: ${YELLOW}${ADMIN_PASSWORD}${NC}"
    echo ""
    echo -e "${BOLD}Database:${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "  Host:          localhost"
    echo -e "  Database:      ${DB_NAME}"
    echo -e "  Username:      ${DB_USER}"
    echo -e "  Password:      ${YELLOW}${DB_PASS}${NC}"
    echo ""
    echo -e "${BOLD}Important Files:${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "  Installation:  ${INSTALL_DIR}"
    echo -e "  Credentials:   /var/www/.user"
    echo -e "  Environment:   ${INSTALL_DIR}/.env"
    if [[ "$WEBSERVER" == "nginx" ]]; then
        echo -e "  Nginx Config:  /etc/nginx/sites-available/phparm"
    else
        echo -e "  Apache Config: /etc/apache2/sites-available/phparm.conf"
    fi
    echo ""
    echo -e "${YELLOW}╔═══════════════════════════════════════════════════════════╗${NC}"
    echo -e "${YELLOW}║  IMPORTANT: Save the credentials above in a secure place! ║${NC}"
    echo -e "${YELLOW}║  They are also saved in /var/www/.user                    ║${NC}"
    echo -e "${YELLOW}╚═══════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${BOLD}Maintenance Commands:${NC}"
    echo "  View logs:         tail -f ${INSTALL_DIR}/storage/logs/*.log"
    if [[ "$WEBSERVER" == "nginx" ]]; then
        echo "  Restart Nginx:     systemctl restart nginx"
        echo "  Restart PHP-FPM:   systemctl restart php8.4-fpm"
    else
        echo "  Restart Apache:    systemctl restart apache2"
    fi
    echo ""
}

# ============================================
# Main Installation Process
# ============================================

main() {
    echo -e "${BOLD}${GREEN}"
    echo "╔═══════════════════════════════════════════════════════════╗"
    echo "║                                                           ║"
    echo "║        PHPArm Full Server Deployment Script               ║"
    echo "║                                                           ║"
    echo "╚═══════════════════════════════════════════════════════════╝"
    echo -e "${NC}"

    check_root
    check_os

    parse_arguments "$@"
    validate_webserver_choice
    interactive_setup

    generate_db_identifiers
    print_info "Generated database identifiers:"
    print_info "  DB_NAME=${DB_NAME}"
    print_info "  DB_USER=${DB_USER}"

    update_system

    # Install webserver first (so ports/modules are sane), then PHP
    install_webserver
    install_php

    install_mariadb
    install_nodejs
    install_composer
    install_tools

    deploy_application
    configure_environment
    install_database
    create_admin_user

    configure_webserver
    set_permissions
    configure_firewall
    install_ssl
    configure_logrotate
    setup_cron

    restart_webserver

    print_summary
}

main "$@"
