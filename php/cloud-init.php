<?php

$k8s_version    = 'v1.11.0';
$crictl_version = 'v1.11.0';
$cni_version    = 'v0.7.1';

if (isset($_SERVER['HTTPS'])) {
  if ($_SERVER['HTTPS'] == "on") { $method = "https"; }
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
<?  foreach (array("kubeadm","kubelet","kubectl") as $file){ ?>
      { 
        "filesystem": "root",
			"path": "/opt/bin/<?=$file?>",
    "contents": {
	"source": "https://storage.googleapis.com/kubernetes-release/release/<?=$k8s_version?>/bin/linux/amd64/<?=$file?>"
        },
        "mode": 755
	  },
<?}?>
      {
        "filesystem": "root",
        "path": "/opt/downloads/cni.tgz",
        "contents": {
		"source": "https://github.com/containernetworking/plugins/releases/download/<?=$cni_version?>/cni-plugins-amd64-<?=$cni_version?>.tgz"
        },
        "mode": 644
      },
      {
        "filesystem": "root",
        "path": "/opt/downloads/crictl.tgz",
        "contents": {
		"source": "https://github.com/kubernetes-incubator/cri-tools/releases/download/<?=$crictl_version?>/crictl-<?=$crictl_version?>-linux-amd64.tar.gz"
        },
        "mode": 644
      },
      {
        "filesystem": "root",
        "path": "/etc/systemd/system/kubelet.service",
        "contents": {
		"source": "https://raw.githubusercontent.com/kubernetes/kubernetes/<?=$k8s_version?>/build/debs/kubelet.service"
        },
        "mode": 644
      },
      {
        "filesystem": "root",
        "path": "/etc/systemd/system/kubelet.service.d/10-kubeadm.conf",
        "contents": {
		"source": "https://raw.githubusercontent.com/kubernetes/kubernetes/<?=$k8s_version?>/build/debs/10-kubeadm.conf"
        },
        "mode": 644
      },
      {
        "filesystem": "root",
        "path": "/opt/bin/k8s_bootstrap.sh",
        "contents": {
          "source": "https://raw.githubusercontent.com/TheCase/tec-kubernetes/master/scripts/k8s_bootstrap.sh"
        },
        "mode": 755
      }
<?
/*
	foreach (array("bridge","dhcp","flannel","host-device","host-local","ipvlan","loopback","macvlan","portmap","ptp","sample","tuning","vlan") as $file){
    $output .= '{ 
        "filesystem": "root",
        "path": "/opt/cni/bin/'.$file.'",
    "contents": {
      "source": "'.$self.'/kubernetes/cni/'.$file.'"
        },
        "mode": 755
    },';
   }
print $output;
      {
        "filesystem": "root",
        "path": "/opt/bin/crictl",
        "contents": {
          "source": "<?=$self?>/kubernetes/crictl/crictl"
        },
        "mode": 755
      },
      {
        "filesystem": "root",
        "path": "/etc/systemd/system/kubelet.service",
        "contents": {
          "source": "<?=$self?>/kubernetes/kubelet/kubelet.service"
        },
        "mode": 400
      },
      {
        "filesystem": "root",
        "path": "/etc/systemd/system/kubelet.service.d/10-kubeadm.conf",
        "contents": {
          "source": "<?=$self?>/kubernetes/kubelet/10-kubeadm.conf"
        },
        "mode": 400
	  }
*/?>
  ]
  },
  "systemd": {
  "units": [
    {
      "name": "bootstrap.service",
      "enable": true,
      "contents": "[Service]\nType=oneshot\nExecStart=/opt/bin/k8s_bootstrap.sh\n\n[Install]\nWantedBy=multi-user.target"
    },
    {
      "enable": true,
      "name": "docker.service"
    },
    {
      "enable": true,
      "name": "kubelet.service"
    }
  ]
  }
}
