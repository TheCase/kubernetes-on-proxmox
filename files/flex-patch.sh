#!/bin/sh

## run on control plane nodes to fix readonly problem with flex

# patch kubelet for flex plugin
sed -i "s:\"$: --volume-plugin-dir=/var/lib/kubelet/volumeplugins\":g" /var/lib/kubelet/kubeadm-flags.env 
# patch controller for flex plugin
sed -i "s:/usr/libexec/kubernetes/kubelet-plugins:/var/lib/kubelet/volumeplugins:g" /etc/kubernetes/manifests/kube-controller-manager.yaml 
systemctl restart kubelet.service
