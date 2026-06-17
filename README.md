TrueNAS Disk Control (SES + SMART + Web UI)
<img width="1192" height="1234" alt="pic_1" src="https://github.com/user-attachments/assets/607a14c7-0e7d-4a03-bfcd-5438f5824af2" />
<img width="1165" height="683" alt="pic_2" src="https://github.com/user-attachments/assets/52bac8ad-613c-4169-a3ea-77141d515672" />

## Fork notes

This fork removes the old hard-coded SES model filters. Enclosures are now detected generically from:

```
lsscsi -g
```

Any `enclosu` row is considered, including Supermicro / LSI `SAS2X36` devices such as:

```
[1:0:24:0] enclosu LSI SAS2X36 0e12 - /dev/sg25
```

Generated diagnostics are written to:

```
hdd_controlere/discovery.txt
hdd_controlere/discovery.json
```

The LED endpoints validate generated `sg_ses` commands server-side before execution. The Docker image also limits passwordless sudo to small validation wrappers instead of allowing raw hardware tools or every command.

`sas3ircu` is used only as a read-only inventory source when it detects the controller. The application calls only:

```
sas3ircu list
sas3ircu <controller> display
```

Those calls are routed through `/usr/local/sbin/tdm-sas3ircu-read`, which rejects destructive `sas3ircu` subcommands such as delete, hotspare, offline/online, locate, boot changes, and log clearing.

If `sas3ircu` does not detect the controller, the app falls back to `lsscsi -g` plus `smartctl -i`. For enclosures like `LSI SAS2X36`, disk H:C:T:L target numbers are used as zero-based SES device slot numbers and the matching `enclosu` `/dev/sgN` device is used for LED identify commands.

LEDs are controlled separately through `/usr/local/sbin/tdm-sg-ses-ident`, which allows only `sg_ses --dev-slot-num=N --set=ident` and `sg_ses --dev-slot-num=N --clear=ident`.


This project is a small utility written mostly in procedural PHP (i do my best in it) that helps visualize and control disks in a TrueNAS system with SAS controllers and SES enclosures.

It was created because managing large disk arrays can be confusing, especially when trying to identify the physical disk corresponding to a specific device (/dev/sdX) or serial number. ( and usually not many people can afford a truenas enclosure that can click a disk and the LED identifier turns on so you can swap that disk)


The goal of this project is to provide a simple visual interface and automation pipeline that:

•	detects SAS controllers and disks (originally tested with LSI SAS3008 flashed IT Mode)

•	maps serial numbers ↔ Linux devices

•	retrieves SMART information

•	detects which disks belong to TrueNAS pools

•	identifies unused disks / or spare disks

•	maps disks to SES slots

•	allows turning disk identification LEDs ON/OFF (tehnically speaking , this is the main goal. )

•	shows all disks in a visual grid that matches the physical enclosure layout

•	see the health status of each disk (personal preferance condition list)

•	see which pool the disk belongs to


________________________________________



Why this project exists : 

TrueNAS already provides powerful tools, but they are mostly CLI-based and not always convenient when dealing with many disks.
When working with large storage systems, especially with external SAS enclosures, it is often difficult to quickly determine:

•	which physical disk corresponds to /dev/sdX 

•	where a disk is located inside the enclosure (im tired of spreadsheets and non labels)


________________________________________

How it works (* it's a bold title it should be like "how it should work")

The system runs a pipeline that collects data from multiple sources:
1.	Detect SAS controllers
2.	Read disk-slot information using `sas3ircu display`, or fall back to `lsscsi -g` target numbers when `sas3ircu` cannot see the controller
3.	Associate disk serial numbers
4.	Collect SMART information using smartctl (for each disk)
5.	Detect SES enclosures generically from `lsscsi -g`
6.	Build SES commands for LED control
7.	Query TrueNAS API to determine:
    o	disks used by pools
    o	spare disks
    o	unused disks

You have to change the ip and api in: config_api.php

________________________________________

Web interface features

Each disk tile displays:

•	slot number

•	device (/dev/sdX)

•	serial number

•	SMART health status

•	pool membership

•	spare status

•	unused disks

You can also:

•	search by serial or device

•	filter by pool

•	view full SMART output

•	turn disk LED ON/OFF

•	regenerate the entire dataset from the UI


Example of disk control command execution:

•	sg_ses --dev-slot-num=0 --set=ident /dev/sg25  (slot 0 is physical bay 1 on SAS2X36)

•	sg_ses --dev-slot-num=0 --clear=ident /dev/sg25

________________________________________

Requirements

This project assumes a system with:

•	TrueNAS SCALE

•	SAS/SATA disks visible in `lsscsi -g`

•	SES compatible enclosures visible as `enclosu` devices in `lsscsi -g`

•	smartctl

•	sas3ircu (optional but used when it supports the controller)

•	sg_ses

•	PHP (CLI + web)

The script also uses the TrueNAS API for retrieving pool and disk information.

Note: On hardware where `sas3ircu` does not detect the controller, the fallback assumes the `lsscsi -g` target number maps to the zero-based SES device slot number. This matches common direct-attached SES layouts such as `[1:0:0:0]` through `[1:0:23:0]` disks with an enclosure like `[1:0:24:0] enclosu ... /dev/sg25`, where `--dev-slot-num=0` lights physical bay 1.

Basically it's a docker container, see the instalation part.

________________________________________

Contributions are very welcome.

I am comfortable with PHP, but I am still learning parts related to:

•	Bash scripting

•	TrueNAS internals

•	SAS / SES environments


So if you have improvements, ideas, or optimizations, feel free to open:

•	Pull Requests

•	Issues

•	Suggestions

Any help is appreciated.
________________________________________

Project status

This project is currently a personal tool that evolved over time while managing a storage system. 

The code is functional but still evolving and may require adjustments depending on:

•	controller models

•	enclosure types

•	TrueNAS versions


________________________________________

Installation from TrueNAS Shell

The easiest deployment path is to pull the prebuilt image from GitHub Container Registry directly on the TrueNAS host.

The container still needs:

•	`/dev` access for `smartctl`, `lsscsi`, `sas3ircu`, and `sg_ses`

•	system time from the host

•	`--privileged`, because SES devices such as `/dev/sg25` may be root-only

Inside the image, the web process cannot run arbitrary hardware commands. It can only use the read-only wrappers for inventory/SMART plus the LED identify wrapper for `sg_ses --dev-slot-num=N --set=ident` and `sg_ses --dev-slot-num=N --clear=ident`.

1. Open a TrueNAS shell or connect over SSH.

Example:

```bash
ssh truenas_admin@YOUR_TRUENAS_IP
```

2. Create a persistent data directory.

Choose a dataset/path that exists on your TrueNAS system. Example:

```bash
sudo mkdir -p /mnt/YOUR_POOL/apps/truenas_interface/data
```

3. Pull the image.

```bash
sudo docker pull ghcr.io/beejeex/truenas-disk-map:latest
```

4. Start the container.

```bash
sudo docker rm -f truenas_interface 2>/dev/null || true

sudo docker run -d \
  --name truenas_interface \
  --restart unless-stopped \
  -p 8585:80 \
  -v /dev:/dev \
  -v /etc/localtime:/etc/localtime:ro \
  -v /mnt/YOUR_POOL/apps/truenas_interface/data:/var/www/html/data \
  --privileged \
  ghcr.io/beejeex/truenas-disk-map:latest
```

5. Open the interface.

```text
http://TRUENAS_IP:8585
```

Example:

```text
http://192.168.1.10:8585
```

After opening the interface, click `Refresh` to generate the disk map files.

The TrueNAS API is optional. Without it, the app still maps slots, reads SMART data, detects SES, and controls identify LEDs. Pool, spare, and unused labels are skipped until API settings are configured.

To add API details, open `API Settings` in the web interface and enter:

•	API URL, for example `https://TRUENAS_IP/api/v2.0`

•	API key from `Credentials` / `API Keys` in the TrueNAS UI

The settings are saved in the mounted `data` directory.

Creating a Read-Only TrueNAS API Key

The API key is only used for optional pool, spare, and unused-disk labels. The app does not need API permission to change disks, pools, datasets, services, users, or system settings.

The API calls used by this app are read-only:

•	`GET /api/v2.0/disk`

•	`GET /api/v2.0/pool`

•	`GET /api/v2.0/boot/get_disks` or `POST /api/v2.0/core/call` with `boot.get_disks`

Recommended setup:

1. In the TrueNAS UI, create or choose a dedicated user for this app, for example `truenas_disk_map_ro`.

2. Give that user the most limited read-only role available in your TrueNAS version. It only needs to read disk, pool, and boot-disk metadata.

3. Do not use a root or full-admin API key unless your TrueNAS version cannot create a lower-privilege key.

4. Open `Credentials` / `API Keys` in the TrueNAS UI. In some versions this is under the user credentials area.

5. Create a new key for the dedicated read-only user and copy it immediately. TrueNAS usually only shows the key once.

6. In this app, open `API Settings`, enter `https://TRUENAS_IP/api/v2.0`, paste the key, and save.

If your TrueNAS version does not expose a clear read-only role for API keys, leave the API settings empty. The app will still map slots, read SMART data, detect SES, and control identify LEDs; it will only skip pool/spare/unused labels.

________________________________________

Updating the Container

From the TrueNAS shell:

```bash
sudo docker pull ghcr.io/beejeex/truenas-disk-map:latest

sudo docker rm -f truenas_interface

sudo docker run -d \
  --name truenas_interface \
  --restart unless-stopped \
  -p 8585:80 \
  -v /dev:/dev \
  -v /etc/localtime:/etc/localtime:ro \
  -v /mnt/YOUR_POOL/apps/truenas_interface/data:/var/www/html/data \
  --privileged \
  ghcr.io/beejeex/truenas-disk-map:latest
```

For a pinned build, use the commit tag:

```bash
ghcr.io/beejeex/truenas-disk-map:COMMIT_SHA
```

________________________________________

Optional: Build from Source

Use this only if you want to modify the code locally on the TrueNAS host.

```bash
git clone https://github.com/Beejeex/truenas-disk-map.git
cd truenas-disk-map
sudo docker build -t truenas_interface .
```

Then run it with the same `/dev`, localtime, data mount, and `--privileged` options shown above, replacing the image name with `truenas_interface`.

________________________________________
Language

The interface and some error messages are currently written in Romanian, which is my native language.

Future versions may include full English localization.

Contributions for translations are welcome.

________________________________________

Credits (Images)
Some of the images used in this project were created specifically for this interface.

I initially searched online for icons that could represent a disk inside an enclosure, but I couldn't find anything that matched what I needed. Because of that, I experimented with AI image generation (ChatGPT image tools) until I obtained a visual style that was close to what I had in mind.

From those generated images I selected a small section and manually cropped it in Paint to create the base disk graphic.

The colored LED indicators used for disk status (OK, warning, error, etc.) were later created with the help of a colleague using Photoshop, since I do not personally know how to work with Photoshop.

So the final images are a combination of:

•	AI generated visuals

•	manual cropping/editing

•	additional graphical elements created by a colleague









