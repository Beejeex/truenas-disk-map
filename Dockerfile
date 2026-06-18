FROM php:8.2-apache

RUN apt update && apt install -y sudo smartmontools sg3-utils lsscsi udev bash curl

COPY sas3ircu /usr/local/bin/sas3ircu
RUN chmod +x /usr/local/bin/sas3ircu

COPY docker/tdm-sas3ircu-read /usr/local/sbin/tdm-sas3ircu-read
COPY docker/tdm-smartctl-read /usr/local/sbin/tdm-smartctl-read
COPY docker/tdm-lsscsi-read /usr/local/sbin/tdm-lsscsi-read
COPY docker/tdm-sg-ses-ident /usr/local/sbin/tdm-sg-ses-ident
RUN chmod 0755 /usr/local/sbin/tdm-sas3ircu-read \
    /usr/local/sbin/tdm-smartctl-read \
    /usr/local/sbin/tdm-lsscsi-read \
    /usr/local/sbin/tdm-sg-ses-ident

# Setam max_execution_time la 180s
RUN echo "max_execution_time = 180" > /usr/local/etc/php/conf.d/custom.ini

COPY . /var/www/html/
WORKDIR /var/www/html/
RUN rm -f /var/www/html/index.html
RUN cd /var/www/html && (git rev-parse --short HEAD 2>/dev/null || echo "dev") > /version.txt
RUN mkdir -p /var/www/html/data /var/www/html/disk_data
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

RUN echo "www-data ALL=(root) NOPASSWD: /usr/local/sbin/tdm-smartctl-read, /usr/local/sbin/tdm-sas3ircu-read, /usr/local/sbin/tdm-lsscsi-read, /usr/local/sbin/tdm-sg-ses-ident" > /etc/sudoers.d/truenas-disk-map \
    && chmod 0440 /etc/sudoers.d/truenas-disk-map

# Entrypoint starts the scheduler daemon + Apache (no host cron needed)
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
