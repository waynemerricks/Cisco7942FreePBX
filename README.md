# Cisco7942 FreePBX Integration

If you're getting rid of CUCM and thinking, "I'd like to use Asterisk (Free PBX in this case) with all these 7942s I have lying around", hopefully this will help you get things up and running.

#### This guide and set of files covers:
 * Free PBX gotchas (v14.0.5.25)
 * Where to get the SIP Firmware for your existing CUCM Phones (Latest available dates to Feb 2017)
 * How to flash/install/use the Firmware
 * Sample Configuration files to get you started
 * Free PBX address book integration via the directory key to mimic CUCM behaviour as closely as possible
 * Making it pretty with background images and custom ringtones
 * Troubleshooting tips
 
#### You will need:
  * A Free PBX server setup and working (VM is fine)
  * Working DHCP for the phones with access to set TFTP Options (Option 66)
  * A Cisco account to download the firmware (it is free to sign up and download the firmware, you don't need an active support contract)
  * An NTP server so the phones can keep time (Windows Active Directory servers do work, failing that any NTP device will do, most home routers can serve NTP too)

#### Things that don't work:
  * Conference calls can not be initiated from the phone (the calls don't join)
  * Phone Error: No Trust List Installed - Due to missing tlv files, you can't get these without active Cisco contracts
  * Phone Error: Error updating locale - Due to missing locale jars, again you need an active Cisco contract to get these
  * Phones are prone to freezing, I'm not sure if this is due to old flaky phones on my part or if this applies to all 7942s
  * Auto update on config changes don't work.  This might be my error not generating proper version stamps but I can't even see the phone polling the TFTP server for changes so I doubt it works at all under SIP firmware.
  
I must add that this is just information cobbled together from many different places over the course of several months. If you recognise files/explanations from elsewhere please send me a message and I'll add it to my list of references.

## FreePBX Setup

Among many other things you'll have to do to get the phones working, there are a few simple settings changes that are required in FreePBX before you get too far down the rabbit hole.

For every extension that will use a 7942 you need to change it to use the CHAN_SIP driver and use Port 5160.

For whatever reason, PJSIP does not work with these phones.  Using CHAN_SIP defaults to port 5160 but doesn't change the port settings if you've already bulk added the extension under PJSIP so if you're having issues registering phones check the driver and port settings.

`Admin --> Applications --> Extensions --> Advanced`

**Set CHAN_SIP**

![FreePBX CHAN_SIP Driver](https://github.com/waynemerricks/Cisco7942FreePBX/raw/master/images/pbx_chan_sip.png "Set CHAN_SIP Driver")

**Set Port to 5160**

![CHAN_SIP Port 5160](https://github.com/waynemerricks/Cisco7942FreePBX/raw/master/images/pbx_port_5160.png "Set Port to 5160")

## Getting the firmware

By default, the CUCM phones are set to the SCCP protocol and will not work under FreePBX, you need to download the SIP firmware directly from Cisco.

[Cisco 7942 SIP Firmware 9.4(2)SR3 February 2017: cmterm-7942_7962-sip.9-4-2SR3-1.zip 6.09 MB](https://software.cisco.com/download/home/281346593/type/282074288/release/9.4%25282%2529SR3)

Make sure you download the ZIP File full of firmware only, the sgn file is not usable.  As mentioned, you'll need a free Cisco account before they let you download these files.

## Flashing the firmware

The 7942s require a TFTP server for all their configuration files and firmware.  The FreePBX server already has TFTP built in for use with other modules so you can use that for minimal effort.  Otherwise look into tftpd-hpa for Debian/Ubuntu, once set up, the procedure is the same as using FreePBX directly (Debian likes to use /srv/tftp, Ubuntu /var/lib/tftpboot).

There are tftp servers available for Windows but I don't use them so can't recommend a good one.  If you're using Windows anything referred to as /tftpboot is the root of your tftp server.

Inside the Firmware zip file you'll have the following files:
 * apps42.9-4-2ES26.sbn
 * cnu42.9-4-2ES26.sbn
 * cvm42sip.9-4-2ES26.sbn
 * dsp42.9-4-2ES26.sbn
 * jar42sip.9-4-2ES26.sbn
 * SIP42.9-4-2SR3-1S.loads
 * term42.default.loads
 * term62.default.loads
 
All of these need to be copied to /tftpboot on FreePBX (or respective directories on Debian/Ubuntu/Windows).

With these in place, the phone still won't do much as you need to set up a bunch of XML files to tell the phone what firmware to load.

The bare minimum to get this to happen are (be careful, file names are case sensitive):
  * XMLDefault.cnf.xml
  * SEP**PUT MAC ADDRESS OF PHONE HERE**.cnf.xml

#### [XMLDefault.cnf.xml](../master/tftpboot/XMLDefault.cnf.xml) - Main System Config

You only need to change one line in this file for the 7942:

`<processNodeName>PUT IP OF PHONE SERVER HERE</processNodeName>`

But you should also check that the load information matches the firmware you downloaded:

`<loadInformation model="Cisco 7942">SIP42.9-4-2SR3-1S</loadInformation>`

The SIP42.9-4-2SR3-1S is taken directly from the SIP42 loads file name.  If you have other models of phone with different firmware you can add multiple loadInformation lines to cover this.

In theory you can also set the directory and services URL for all phones in this config file however be aware that not all phones support the same set of Cisco XML elements so if you're mixing models you might want to keep this config to each specific phone.

#### [SEPMACADDRESS.cnf.xml](../master/tftpboot/SEPMACADDRESS.cnf.xml) - Phone Specific Config

This is where the bulk of the phone settings live.  You must rename this file to match the MAC address of the phone you're configuring.  You can find this from the sticker on the back of the phone or via Settings --> Model Information --> MAC Address.  For example my nearest phone has a MAC of 00270DBD73DD so I would rename the file SEP00270DBD73DD.cnf.xml.

These files aren't well documented, various guides have different explanations.  There doesn't seem to be anything useful available from Cisco.

At the minimum look into these lines:
  * **sshUserId** - Set SSH user login name here
  * **sshPassword** - Password for SSH (once logged in it asks you to login again as a phone user, use debug/debug for useful access)
  * devicePool / dateTimeSetting / ntps / ntp / **name** - Set the IP address of an NTP server to help the phones keep the correct time
  * callManagerGroup / members / member / callManager / **processNodeName** - Set the IP address of FreePBX here

The sipLines section is probably where you want to spend most of your time, this lays out the various labels on the phone and has the extension registration name and password:

  * sipLines / line / **featureLabel** - This is the Line label that shows to the left of the line button
  * sipLines / line / **name** - This is the extension number the phone needs to use
  * sipLines / line / **displayName** - This is how the phone name will be displayed when phoning someone else
  * sipLines / line / **authName** - This is what the phone will use to register with FreePBX (the extension number)
  * sipLines / line / **authPassword** - This is the extension password in FreePBX.  
  
  **The password must not be longer than 12 characters.**  The default FreePBX passwords are too long for the phone and you'll get config parse errors if you try to use them.  Please note, different guides mention different maximum lengths, as far as I can tell this varies based on whatever phone model and firmware version you're using, 12 worked for me but try and use the maximum length you can for security reasons (its not fun having massive phone bills).

  * sipLines / line / **contact** - This is the phone extension number, I'm not sure exactly where this appears on the phone

  The 7942s have 2 lines you can use, you specify this by copying the entire line section and changing the button="1" bit to button="2".  If you have a phone with 4 or more lines you simply increment the button="X" for whatever line you're configuring.

  E.g.

```xml
<sipLines>
  <line button="1">
    ...
  </line>
  <line button="2">
    ...
  </line>
</sipLines>
```

  * commonProfile / **phonePassword** - This is the password required on the phone to enter the unlocked settings menu.  
  
  Bear in mind you'll have to use the phone keypad to "type" this so perhaps don't be too paranoid with 24 digit passwords.  You access the unlocked settings by keying `**#` on the phone when in the settings menu.  You'll then get a message "settings unlocked".  
  
  The settings themselves are fairly boring, you can see some extra information but can't change anything worth messing with.
  
  * **loadInformation** - This needs to match the firmware name, the same as in the XMLDefault.cnf.xml file
  * **directoryURL** - This is the entry point for the phonebook integration via the Directory key on the front of the phone.  You'll need to change the IP Address to that of your FreePBX box (see phonebook integration below)
  
### Applying / Installing the firmware

You need to factory reset the phone while attached to your new phone network (access to the TFTP/FreePBX server required for this step).

To factory reset the 7942s:
  1. power on the phone while holding the # key.  
  2. Keep it held until the orange line buttons (top right) are flashing.
  3. Press the keypad buttons in turn, `123456789*0#`
  4. The phone will now reboot and load your SIP firmware (and SEP config)

This takes a few minutes to install, do not mess with the phone while this is happening or you'll potentially brick the phone.

At this point if your XML settings are correct the phone will be usable but there are a few extras that can make life easier.

## [dialplan.xml](../master/tftpboot/dialplan.xml) - Making the phone respond without waiting to dial

By now you may have noticed a dialplan.xml referenced in the phone config and possible error messages relating to dialplans.  This file defines how the phone behaves when you enter certain numbers.

You need to put this file in the root of your tftpboot directory.  The sample file is UK specific however you basically set up templates where you want different behaviours.  

For example what if you want the 999 emergency number to dial instantly with no delay:
`<TEMPLATE MATCH="999" Timeout="0"/>`

You can adjust time outs based on patterns that you use in short:
  * Use specific numbers for matches e.g. 9 matches 9
  * Use dots to signify any number, e.g. .... = any 4 numbers matches
  * Use * for any amounts of numbers (standard wildcard)
  * Use \* to escape * for use with Asterisk feature codes

So if you want to match any number that starts with 3, has 2 numbers then a number 4 and then after that you don't care what happens you would match it like this:
`<TEMPLATE MATCH="3..4*" Timeout="0"/>`

Why you would want to do this is debatable but now you know.

**Note:**  This does not change any dialplans you have set up with FreePBX, this is purely changing how the phone responds before talking to FreePBX.

## Custom Ringtones

By default you only get the Chirp 1 and 2 with the SIP firmware for the 7942s.  During a deep google dive, I managed to find some extra files hosted at a random website by a person who seems to have been mucking around with Cisco phones several years ago. 

http://www.loligo.com/asterisk/cisco/79xx/current/

I've added the files that work to this repo just in case the site goes away.  You can also make your own sounds but they have to be a specific format (taken from Cisco directly):

https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/all_models/xsi/8_5_1/xsi_dev_guide/supporteduris.html#wpxref24086

> The audio files for the rings must meet the following requirements for proper playback on Cisco Unified IP Phones:
>
>  * Raw PCM (no header)
>  * 8000 samples per second
>  * 8 bits per sample
>  * uLaw compression
>  * Maximum ring size—16080 samples
>  * Minimum ring size—240 samples
>  * Number of samples in the ring is evenly divisible by 240.
>  * Ring starts and ends at the zero crossing.
>
> To create PCM files for custom phone rings, you can use any standard audio editing packages that support these file format requirements.

Once you have your raw ring file you need to save it to the root of your tftpboot directory.  The 7942's sadly don't support reading rings from anything but the root.  Some TFTP servers apparently support alias commands for redirecting file requests but I couldn't get this to work with the one built in to FreePBX.

With everything in place you need another xml file in the root [ringlist.xml](../master/tftpboot/ringlist.xml)

The format is pretty simple, you need a ring section for each ringer file.  The display name is how it is labelled on the phone and the filename is the case sensitive TFTP file name.  The above file is setup for every raw file found on loligo.com.

**Please note** I'm not sure if there is an upper limit to the amount of rings you can have in a list but the phone directory has a limit of 32 items so if you start getting errors check you added more than 32 in the ringlist file.

```xml
<CiscoIPPhoneRingList>
    <Ring>
        <DisplayName>Are you there Male?</DisplayName>
        <FileName>AreYouM.raw</FileName>
    </Ring>
    <Ring>
        <DisplayName>Are you there Female?</DisplayName>
        <FileName>AreYouThereF.raw</FileName>
    </Ring>
</CiscoIPPhoneRingList>
```

You have to then select the ring tones you want to use via Settings -> User Preferences --> Rings:

![7942 User Ringtones](https://github.com/waynemerricks/Cisco7942FreePBX/raw/master/images/7942_custom_ringtones.png "Custom Ringtones")

## [List.xml](../master/tftpboot/Desktops/320x196x4/List.xml) Custom Backgrounds

The phone will automatically look for an xml file containing a list of phone backgrounds you can use.  Again this is selected per phone via Settings --> User Preferences --> Background Images:

![7942 User Backgrounds](https://github.com/waynemerricks/Cisco7942FreePBX/raw/master/images/7942_custom_backgrounds.png "Custom Backgrounds")

This xml file must be stored in /tftpboot/Desktops/320x196x4/List.xml.  Different phones require different resolutions and locations for this file.  The 7942s specifically can only display the following:
  * 320px width
  * 196px height
  * 4 "Colours" (grey scale ish)

The guides all say you must use grey scale mode and bmp files but the 7942s can interpret standard Colour PNG files.  Just be careful with the colours you use, although you can copy/paste your company logo the phone will match the colours based on the following simple 4 colour pallette:
  * Black
  * Dark Grey
  * Light Grey
  * Screen Colour (white/no colour at all)

In short stick with simple logos, don't try for the Mona Lisa without some serious monochrome pixel art optimisations.  Also take care because all of the phone labels are drawn on top of this logo without any consideration (text basically disappears if your logo is black).

You also need to provide a thumbnail for each image that the phone will use in the background selection menu.  The same limitations apply but now the size is limited to 80 x 49 pixels.

```xml
<CiscoIPPhoneImageList>
    <ImageItem Image="TFTP:Desktops/320x196x4/ubuntu-tn.png"
       URL="TFTP:Desktops/320x196x4/ubuntu.png"/>
    <ImageItem Image="TFTP:Desktops/320x196x4/tux-tn.png"
       URL="TFTP:Desktops/320x196x4/tux.png"/>
</CiscoIPPhoneImageList>
```

As you can see this is fairly simple too, you may be able to substitute (untested) the TFTP URL with a standard HTTP url if you have images elsewhere (or as part of the FreePBX webserver).  For sanity reasons, I'm keeping the graphic files with the xml file.

The Image="" section is for the thumbnail, the URL is the location of the full image.
