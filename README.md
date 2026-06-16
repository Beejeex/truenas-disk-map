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

The `sas3ircu` dependency is still used for one read-only purpose: controller-to-slot disk inventory. The application calls only:

```
sas3ircu list
sas3ircu <controller> display
```

Those calls are routed through `/usr/local/sbin/tdm-sas3ircu-read`, which rejects destructive `sas3ircu` subcommands such as delete, hotspare, offline/online, locate, boot changes, and log clearing. LEDs are controlled separately through `/usr/local/sbin/tdm-sg-ses-ident`, which allows only `sg_ses --set=ident` and `sg_ses --clear=ident`.


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
2.	Read disk information using sas3ircu
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

•	sg_ses --set=ident  (found this in the LSI manuals and works to trigger my case)

•	sg_ses --clear=ident

________________________________________

Requirements

This project assumes a system with:

•	TrueNAS SCALE

•	SAS controller supported by `sas3ircu` for controller-to-slot disk data

•	SES compatible enclosures visible as `enclosu` devices in `lsscsi -g`

•	smartctl

•	sas3ircu

•	sg_ses

•	PHP (CLI + web)

The script also uses the TrueNAS API for retrieving pool and disk information.

Note: SES visibility alone is not enough to build the disk map. The app still needs controller disk data from `sas3ircu display` so it can map serial numbers to enclosure slots.

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

Installation

The easiest way to run the project is using Docker directly on the TrueNAS host.

The interface needs access to:

•	/dev (for smartctl / sg_ses)

•	system time

•	privileged mode for hardware commands

________________________________________

Installation

The easiest way to run the project is using Docker directly on the TrueNAS host.

The interface needs access to:

•	/dev (for smartctl / sg_ses)

•	system time

•	privileged mode for hardware commands


1. Connect to TrueNAS via SSH
   
Login to your TrueNAS system:

ssh truenas_admin@YOUR_TRUENAS_IP

3. Create a working directory
Create a folder where the project will live.

Example:

mkdir -p /home/truenas_admin/dockere/truenas_interface


Enter the folder:

cd /home/truenas_admin/dockere/truenas_interface

5. Upload the project files
   
Upload all project files into this directory.

You can use:

•	SCP

•	SFTP

•	WinSCP

•	Mobaxterm (im using this , I like it more)


7. Build the Docker image
•	Inside the project directory run:
sudo docker build -t truenas_interface .


8. Run the container
Run the container with hardware access:
```
sudo docker run -d \
--name truenas_interface \
--restart unless-stopped \
-p 8585:80 \
-v /dev:/dev \
-v /etc/localtime:/etc/localtime:ro \
--privileged \
truenas_interface
```

7. Open the interface
Open your browser:
```
http://TRUENAS_IP:8585
```
Example:
```
http://192.168.1.10:8585
```

________________________________________

Optional: Development mode (no rebuild needed)
If you want to modify the interface without rebuilding the container, you can mount the web folder.
Example:
```
sudo docker run -d \
--name truenas_interface \
--restart unless-stopped \
-p 8585:80 \
-v /dev:/dev \
-v /etc/localtime:/etc/localtime:ro \
-v /home/truenas_admin/dockere/truenas_interface:/var/www/html \
--privileged \
truenas_interface
```

Now every modification you make in:
```
  /home/truenas_admin/dockere/truenas_interface
```
will be instantly visible in the web interface without rebuilding the container.
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









