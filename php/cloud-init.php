<?php

if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == "on") {
  $method = "https";
}else{ 
  $method = "http"; 
}
$self = $method."://".$_SERVER['SERVER_ADDR'];

$sshkeys = file_get_contents('authorized_keys');

header('Content-Type: application/json');

?>
{
  "ignition": {
    "config": {},
    "security": {
      "tls": {}
    },
    "timeouts": {},
    "version": "2.2.0"
  },
  "networkd": {},
  "passwd": {
    "users": [
      {
        "name": "root",
        "sshAuthorizedKeys": [
          "<?=rtrim($sshkeys)?>"
        ]
      }
    ]
  },
  "storage": {
    "disks": [
      {
        "device": "/dev/sda",
        "partitions": [
          {
            "label": "ROOT"
          }
        ],
        "wipeTable": true
      }
    ],
    "filesystems": [
      {
    "mount": {
          "device": "/dev/sda1",
      "format": "btrfs",
      "options": [ "--force", "--label=ROOT" ]
        }
    }
    ],
  "files": [
      {
        "filesystem": "root",
        "path": "/opt/bin/k8s_bootstrap.sh",
        "contents": {
          "source": "https://raw.githubusercontent.com/TheCase/kubernetes-coreos-on-proxmox/master/files/k8s_bootstrap.sh"
        },
        "mode": 755
      },
      {
        "filesystem": "root",
        "path": "/etc/docker/daemon.json",
        "contents": {
        "source": "https://raw.githubusercontent.com/TheCase/kubernetes-coreos-on-proxmox/master/files/docker-daemon.json"
        },
        "mode": 644
      },
      {
        "filesystem": "root",
        "path": "/opt/bin/flex-patch.sh",
        "contents": {
        "source": "https://raw.githubusercontent.com/TheCase/kubernetes-coreos-on-proxmox/master/files/flex-patch.sh"
        },
        "mode": 644
      }
  ]
  },
  "systemd": {
  "units": [
    {
      "name": "bootstrap.service",
      "enable": true,
      "contents": "[Service]\nType=oneshot\nExecStart=/opt/bin/k8s_bootstrap.sh\n\n[Install]\nWantedBy=multi-user.target"
    }
  ]
  }
}
