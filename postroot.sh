#!/bin/bash
# Will be executed as user "root".
# v1.0.0: bookworm-/LoxBerry-4-tauglich gemacht (config.txt-Pfad, Guards).

echo "<INFO> Stopping Kodi if it is running..."
systemctl stop kodi 2>/dev/null || true
echo "<INFO> Try to kill remaining Kodi processes..."
killall kodi 2>/dev/null || true
killall kodi-standalone 2>/dev/null || true
killall kodi.bin 2>/dev/null || true

echo "<INFO> Creating user kodi"
useradd -d /home/kodi -m kodi 2>/dev/null || true
echo "<INFO> Creating group input"
addgroup --system input 2>/dev/null || true

# Add kodi user to groups
echo "<INFO> Adding kodi to groups audio, video, input, dialout, plugdev, tty, render"
for grp in audio video input dialout plugdev tty render gpio; do
    usermod -a -G "$grp" kodi 2>/dev/null || true
done

# Install Kodi as a service
echo "<INFO> Copy init.d startup script"
cp -f data/kodi /etc/init.d/kodi
chmod +x /etc/init.d/kodi
echo "<INFO> Install Kodi to run automatically at startup"
systemctl daemon-reload
systemctl enable kodi 2>/dev/null || true

# Raspberry Pi OS bookworm (LoxBerry 4): config.txt liegt unter /boot/firmware/
CONFIGTXT="/boot/config.txt"
if [ -f /boot/firmware/config.txt ]; then
    CONFIGTXT="/boot/firmware/config.txt"
fi

if [ -f "$CONFIGTXT" ]; then
    if [ ! -f "${CONFIGTXT}.kodiplugin" ]; then
        echo "<INFO> Creating backup of your $CONFIGTXT as config.txt.kodiplugin"
        cp "$CONFIGTXT" "${CONFIGTXT}.kodiplugin"
    fi
    # gpu_mem nur auf aelteren Pis sinnvoll (Pi <= 3); auf Pi 4/5 verwaltet der
    # Treiber den Speicher selbst - dort nichts erzwingen.
    PIMODEL=$(tr -d '\0' < /proc/device-tree/model 2>/dev/null || echo "")
    case "$PIMODEL" in
        *"Pi 4"*|*"Pi 5"*|*"Pi 500"*|*"Compute Module 4"*|*"Compute Module 5"*)
            echo "<INFO> $PIMODEL erkannt - gpu_mem wird nicht gesetzt (nicht noetig)."
            ;;
        *)
            echo "<INFO> Setting GPU memory to 192MB in $CONFIGTXT"
            awk -v s="gpu_mem=192" '/^gpu_mem=/{$0=s;f=1} {a[++n]=$0} END{if(!f)a[++n]=s;for(i=1;i<=n;i++)print a[i]>ARGV[1]}' "$CONFIGTXT"
            ;;
    esac
else
    echo "<WARNING> Keine config.txt gefunden - GPU-Einstellung uebersprungen."
fi

echo "<INFO> Creating Kodi settings directory"
mkdir -p /home/kodi/.kodi/userdata
cp -v data/advancedsettings.xml /home/kodi/.kodi/userdata
mkdir -p /home/kodi/.kodi/addons
cp -v -R data/addons/. /home/kodi/.kodi/addons/
chown -R kodi /home/kodi

exit 0
