#!/bin/bash

mkdir -p /opt/cni/bin
tar -C /opt/cni/bin/. -xzf /opt/downloads/cni.tgz
tar -C /opt/bin/. -xzf /opt/downloads/crictl.tgz
sed -i "s:/usr/bin:/opt/bin:g" /etc/systemd/system/kubelet.service
sed -i "s:/usr/bin:/opt/bin:g" /etc/systemd/system/kubelet.service.d/10-kubeadm.conf

systemctl daemon-reload
systemctl restart kubelet
