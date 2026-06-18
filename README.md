# TrueNAS Disk Map

TrueNAS Disk Map is a small web utility for mapping disks, SES enclosure slots, SMART health, pool membership, and identify LEDs on TrueNAS SCALE systems.

It is meant for systems where `/dev/sdX` device names, serial numbers, and physical drive bays are hard to keep straight. The app builds a visual disk layout, lets you inspect SMART data, and can turn a bay identify LED on or off.

## Current Image

```text
ghcr.io/beejeex/truenas-disk-map:latest
```

Pinned builds are also published by commit tag, for example:

```text
ghcr.io/beejeex/truenas-disk-map:783bd0b
```

## What It Does

- Detects SES enclosures from `lsscsi -g`.
- Supports generic `enclosu` devices, including LSI/Supermicro `SAS2X36`.
- Uses `sas3ircu` only as an optional read-only inventory source.
- Falls back to `lsscsi -g` plus `smartctl` when `sas3ircu` cannot detect the controller.
- Maps slot to disk to serial where the enclosure layout exposes matching target numbers.
- Reads SMART identity and health data.
- Shows model, capacity, power-on hours, temperature, serial, and selected SMART counters.
- Shows TrueNAS pool, spare, and unused labels when the optional API key is configured.
- Lets you turn SES identify LEDs on and off per disk.
- Writes diagnostics to `disk_data/discovery.txt` and `disk_data/discovery.json`.

## Safety Model

The container needs broad hardware access because SCSI generic enclosure devices can be root-only. The app itself limits what the web process can execute.

Allowed hardware actions:

- Read-only inventory through wrapper scripts.
- Read-only SMART queries through wrapper scripts.
- SES identify LED on/off through a strict wrapper.

LED commands are limited to generated commands of this form:

```bash
sg_ses --dev-slot-num=N --set=ident /dev/sgX
sg_ses --dev-slot-num=N --clear=ident /dev/sgX
```

The web process cannot run arbitrary shell commands. The Docker sudoers file only allows these wrappers:

- `/usr/local/sbin/tdm-smartctl-read`
- `/usr/local/sbin/tdm-sas3ircu-read`
- `/usr/local/sbin/tdm-lsscsi-read`
- `/usr/local/sbin/tdm-sg-ses-ident`

`sas3ircu` is not required for LED control. It is used only for:

```bash
sas3ircu list
sas3ircu <controller> display
```

If `sas3ircu` returns an error, the app falls back to `lsscsi -g`.

## Hardware Notes

The app detects enclosure rows like this:

```text
[1:0:24:0] enclosu LSI SAS2X36 0e12 - /dev/sg25
```

For layouts like:

```text
[1:0:0:0]  disk ... /dev/sdb /dev/sg1
[1:0:1:0]  disk ... /dev/sdc /dev/sg2
...
[1:0:24:0] enclosu LSI SAS2X36 0e12 - /dev/sg25
```

the fallback treats the target number as the zero-based SES slot number. On a SAS2X36 layout, slot `0` is physical bay `1`.

Manual LED test:

```bash
sudo sg_ses --dev-slot-num=0 --set=ident /dev/sg25
sudo sg_ses --dev-slot-num=0 --clear=ident /dev/sg25
```

## Requirements

- TrueNAS SCALE.
- Docker or TrueNAS Apps Custom App.
- Disks visible in `lsscsi -g`.
- SES enclosure visible as an `enclosu` device in `lsscsi -g`.
- `/dev` mounted into the container.
- Privileged container mode.

The image includes the required userland tools:

- `lsscsi`
- `sg_ses`
- `smartctl`
- `sas3ircu`

## Deployment Option 1: TrueNAS Custom App UI

This is the recommended deployment path when you want the app managed by the TrueNAS Apps UI.

### Remove Old Shell Container

If you previously started the container manually from the shell, remove it first:

```bash
sudo docker rm -f truenas_interface
sudo docker rmi ghcr.io/beejeex/truenas-disk-map:latest 2>/dev/null || true
```

Keep the old data directory if you want to preserve API settings.

### Create Host Paths

Use a dataset/path that exists on your TrueNAS system:

```bash
sudo mkdir -p /mnt/YOUR_POOL/apps/truenas-disk-map/data
sudo mkdir -p /mnt/YOUR_POOL/apps/truenas-disk-map/disk_data
sudo chown -R 33:33 /mnt/YOUR_POOL/apps/truenas-disk-map
sudo chmod -R 775 /mnt/YOUR_POOL/apps/truenas-disk-map
```

Replace `YOUR_POOL` with your pool name.

### Install Custom App

In the TrueNAS UI, open:

```text
Apps -> Discover Apps -> Custom App
```

Use these settings.

Application name:

```text
truenas-disk-map
```

Image Configuration:

```text
Repository: ghcr.io/beejeex/truenas-disk-map
Tag: latest
Pull Policy: Always pull image
```

If `Always pull image` is not available, use `Pull the image if it is not already present on the host`.

Container Configuration:

```text
Hostname: truenas-disk-map
Entrypoint: leave empty
Command: leave empty
```

Optional environment variable:

```text
TZ=Europe/Brussels
```

Security Context Configuration:

```text
Privileged Mode: enabled
Run as non-root: disabled or default
Read-only root filesystem: disabled
Allow privilege escalation: enabled if shown
```

Network Configuration:

```text
Host Network: disabled
Container Port: 80
Host Port: 8585
Protocol: TCP
```

Portal Configuration:

```text
Protocol: HTTP
Port: 8585
Path: /
```

Storage Configuration:

Add these host path mounts:

```text
Host Path: /dev
Mount Path: /dev
Read Only: disabled
```

```text
Host Path: /mnt/YOUR_POOL/apps/truenas-disk-map/data
Mount Path: /var/www/html/data
Read Only: disabled
```

```text
Host Path: /mnt/YOUR_POOL/apps/truenas-disk-map/disk_data
Mount Path: /var/www/html/disk_data
Read Only: disabled
```

Install the app, then open:

```text
http://TRUENAS_IP:8585
```

After the page loads, click `Refresh` to generate the disk map files.

## Deployment Option 2: TrueNAS Install via YAML

TrueNAS also has an `Install via YAML` option in the Apps `Discover` screen. This is usually faster than filling every Custom App field manually.

In the TrueNAS UI, open:

```text
Apps -> Discover Apps -> Install via YAML
```

Name:

```text
truenas-disk-map
```

Custom Config:

```yaml
services:
  truenas-disk-map:
    image: ghcr.io/beejeex/truenas-disk-map:latest
    pull_policy: always
    hostname: truenas-disk-map
    restart: unless-stopped

    privileged: true

    environment:
      TZ: Europe/Brussels

    ports:
      - "8585:80/tcp"

    volumes:
      # Hardware access required for disks, SMART, and SES LEDs.
      - type: bind
        source: /dev
        target: /dev

      # Use the TrueNAS host timezone.
      - type: bind
        source: /etc/localtime
        target: /etc/localtime
        read_only: true

      # Automatically managed persistent application storage.
      - type: volume
        source: app-data
        target: /var/www/html/data

      - type: volume
        source: disk-data
        target: /var/www/html/disk_data

    command:
      - bash
      - -lc
      - |
        mkdir -p /var/www/html/data /var/www/html/disk_data
        chown -R www-data:www-data \
          /var/www/html/data \
          /var/www/html/disk_data
        chmod -R u+rwX,g+rwX,o-rwx \
          /var/www/html/data \
          /var/www/html/disk_data
        exec apachectl -D FOREGROUND

volumes:
  app-data:
  disk-data:
```

If your TrueNAS version rejects `pull_policy`, remove that line and use the app upgrade/redeploy action to pull a newer image later.

Open:

```text
http://TRUENAS_IP:8585
```

After the page loads, click `Refresh`.

## Deployment Option 3: TrueNAS Shell

Use this if you prefer a manual Docker container.

```bash
sudo mkdir -p /mnt/YOUR_POOL/apps/truenas-disk-map/data
sudo mkdir -p /mnt/YOUR_POOL/apps/truenas-disk-map/disk_data
sudo chown -R 33:33 /mnt/YOUR_POOL/apps/truenas-disk-map
sudo chmod -R 775 /mnt/YOUR_POOL/apps/truenas-disk-map
```

Pull and run:

```bash
sudo docker pull ghcr.io/beejeex/truenas-disk-map:latest

sudo docker rm -f truenas_interface 2>/dev/null || true

sudo docker run -d \
  --name truenas_interface \
  --restart unless-stopped \
  -p 8585:80 \
  -v /dev:/dev \
  -v /etc/localtime:/etc/localtime:ro \
  -v /mnt/YOUR_POOL/apps/truenas-disk-map/data:/var/www/html/data \
  -v /mnt/YOUR_POOL/apps/truenas-disk-map/disk_data:/var/www/html/disk_data \
  --privileged \
  ghcr.io/beejeex/truenas-disk-map:latest
```

Open:

```text
http://TRUENAS_IP:8585
```

Click `Refresh` after first load.

## Updating

For the Custom App UI, edit the app and make sure the image tag is `latest` with pull policy set to pull the image again. Then redeploy or upgrade the app.

For the shell deployment:

```bash
sudo docker pull ghcr.io/beejeex/truenas-disk-map:latest

sudo docker rm -f truenas_interface

sudo docker run -d \
  --name truenas_interface \
  --restart unless-stopped \
  -p 8585:80 \
  -v /dev:/dev \
  -v /etc/localtime:/etc/localtime:ro \
  -v /mnt/YOUR_POOL/apps/truenas-disk-map/data:/var/www/html/data \
  -v /mnt/YOUR_POOL/apps/truenas-disk-map/disk_data:/var/www/html/disk_data \
  --privileged \
  ghcr.io/beejeex/truenas-disk-map:latest
```

## TrueNAS API Settings

The TrueNAS API is optional.

Without API settings, the app still:

- Maps slots.
- Reads SMART data.
- Detects SES enclosures.
- Controls identify LEDs.

With API settings, the app can also label:

- Pool disks.
- Spare disks.
- Unused disks.

Configure it from the web UI:

```text
API Settings
```

Use:

```text
API URL: https://TRUENAS_IP/api/v2.0
API Key: your TrueNAS API key
```

The settings are saved in:

```text
/var/www/html/data/config_api.local.php
```

If you used the deployment examples above, that file persists on the host in:

```text
/mnt/YOUR_POOL/apps/truenas-disk-map/data
```

## Creating a Read-Only API Key

The API key is used only for optional labels (pool, spare, unused). The app does not need permission to change disks, pools, datasets, users, services, or system settings.

The app uses these read-only API calls:

- `GET /api/v2.0/disk`
- `GET /api/v2.0/pool`
- `GET /api/v2.0/boot/get_disks`
- `POST /api/v2.0/core/call` with `boot.get_disks`

TrueNAS 25.04 and newer use user-linked API keys. API keys inherit the access rights of the selected user, so the safest setup is a dedicated low-privilege user.

### Step 1: Create a dedicated user

1. Log in to the TrueNAS web UI.
2. Go to **Credentials → Users** and click **Add**.
3. Fill in the form:

| Field | Value |
|---|---|
| **Username** | `tdm-api` (or any name you prefer) |
| **Password** | Set a strong password |
| **Confirm Password** | Repeat the password |
| **Disable Password** | **Unchecked** (must be off to set a password) |
| **TrueNAS Access** | Checked, select **Readonly Admin** from the dropdown |

4. Leave all other fields at their defaults (Full Name, Email, UID, Home Directory).
5. Click **Save**.

### Step 2: Create an API key

1. Go to **Credentials → Users**, click the `tdm-api` user you just created.
2. Scroll to **API Keys** and click **Add**.
3. Give it a name like `truenas-disk-map`.
4. Click **Add**, then **copy the key immediately** — TrueNAS shows it only once.

### Step 3: Configure the app

1. Open the TrueNAS Disk Map web UI.
2. Click **API Settings**.
3. Enter the **API URL**: `https://your-truenas-ip/api/v2.0`
4. Paste the **API Key** you copied.
5. Click **Save**.

The disk map will now show pool names, spare labels, and unused disk markers.

> **Note:** The Readonly Admin role gives read access to pool membership, disk lists, and boot devices — exactly what the disk map needs. No write access to your TrueNAS.
>
> If your TrueNAS version does not have a Readonly Admin role, leave API settings empty. The app still works for slot mapping, SMART, SES detection, and identify LEDs.

## Troubleshooting

Check hardware visibility inside the running container:

```bash
sudo docker exec -it truenas_interface bash -lc '
echo "=== SG devices ==="
ls -l /dev/sg* 2>/dev/null

echo
echo "=== SCSI devices ==="
lsscsi -g

echo
echo "=== Enclosures ==="
lsscsi -g | grep -i enclosu

echo
echo "=== Required commands ==="
command -v lsscsi
command -v sg_ses
command -v smartctl
command -v sas3ircu
'
```

Expected enclosure example:

```text
[1:0:24:0] enclosu LSI SAS2X36 0e12 - /dev/sg25
```

Regenerate files from the shell:

```bash
sudo docker exec -it truenas_interface bash -lc 'php /var/www/html/run_regen.php'
```

### Automatic background refresh (self-contained, no host cron needed)

The container runs its own internal scheduler — a lightweight PHP daemon that checks every 60 seconds and triggers a refresh when the configured interval has elapsed. **No host cron setup required.**

**How it works:**

1. `daemon.php` runs as a background process inside the container, started automatically by the entrypoint.
2. Every 60 seconds it calls `scheduler.php`, which checks `disk_data/.cron_config.json` and triggers `run_regen.php` (CLI mode) only when the configured interval has elapsed.
3. A lock file prevents overlapping runs. The refresh runs **outside** the web server — the website stays fully responsive.
4. The frontend polls `refresh_status.php` every 30s and shows an "Auto-refresh running…" badge when active.
5. **Configure it from the UI** — click the ⚙ gear icon next to the Refresh button to enable/disable and set the interval (5 min to 24 hours).

**It just works** — start the container, open the UI, click ⚙, enable, pick your interval. The daemon log is at `disk_data/.daemon.log` if you ever need to check it.

**Manual CLI refresh** (still works):

```bash
sudo docker exec -it truenas_interface bash -lc 'php /var/www/html/run_regen.php'

Generated files and diagnostics are in:

```text
/var/www/html/disk_data
```

If the UI shows `0 files analyzed`, run `Refresh` and then check:

```text
disk_data/discovery.txt
disk_data/discovery.json
```

## Build From Source

Use this only if you want to modify the code locally.

```bash
git clone https://github.com/Beejeex/truenas-disk-map.git
cd truenas-disk-map
sudo docker build -t truenas_interface .
```

Run it with the same `/dev`, `data`, `disk_data`, localtime, port, and `--privileged` settings shown above.

## Project Status

This is a personal tool that is evolving around real TrueNAS hardware. It is functional, but enclosure/controller mappings can vary by hardware.

Useful test output for new hardware:

- `lsscsi -g`
- `sg_ses --join /dev/sgX`
- `smartctl -x /dev/sdX`
- `sas3ircu list`
- `sas3ircu <controller> display`

Contributions, issues, and hardware reports are welcome.

## Credits

The disk images used by the UI were created specifically for this project. The base disk graphics started from AI-generated visual experiments, then were manually cropped and edited. Additional LED/status elements were created with Photoshop help from a colleague.
