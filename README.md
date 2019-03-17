# Kubernetes cluster with CoreOS using Proxmox and pfSense

My install of a Kubernetes Cluster on Proxmox VMs using PXE netboot with CoreOS images

## PXE Boot and Ignition
### Web server and PHP Ingition generator script
I use a combination of a TFTP server, PXEboot server and web server for the PXE boot process.  I use a DHCP server on my pfSense router that points netboot clients to a pxe/tftp server on my Synology NAS.  If you would like more information on this subject, file an issue and I'll help you out.  You can find my PXE configuration in the `pxeboot` directory.  The netboot init process points to php script that serves an Ignition cloud-init for provisioning the master/worker nodes.  This script is in the `php` folder of this repo.  In the web root directory, also make sure to populate a `authorized_keys` file with the SSH keys you wish to use to access your fresh Kubernetes nodes.

#### PXE Tricks
I have a number of selectable boot options on my PXE server, and I have a few tricks that assure my soon-to-be Kubernetes nodes will boot without manual interaction.  I use custom MAC addresses so I can predefine a group of IP addresses on my DHCP server.  Then I create a symlink that is a hex representation of the IP address group of the Kubernetes nodes:

For example, `0a01010` covers the IP Address group 10.1.1.1/28
```
ln -s kubernetes 0A01010
```

If you want finer granularity, you can also use a MAC address pattern to direct individual nodes to your pxeboot configuration:

Example: MAC of 12:34:56:AA:BB:CC
```
ln -s kubernetes 01-12-34-56-aa-bb-cc
```
The downside of this method is that you need to create one symlink per desired node.

More information on pxe booting via IP and MAC addresses, see this [https://docs.oracle.com/cd/E24628_01/em.121/e27046/appdx_pxeboot.htm#EMLCM12198](link)

## Bootstrap
There are scripts in the `proxmox` directory that will aid you in quickly provisioning the master and worker nodes.  They will boot on creation, find the pxe server, tftp boot the CoreOS images and apply the cloud-init configuration.  The nodes will be ready to run `kubeadm`, to either initialize a master or join to a master as workers.  

### Kubeadm init the master node
```
kubeadm init --pod-network-cidr=10.244.0.0/16 --apiserver-advertise-address=$(ifconfig ens18 | grep "inet " | awk {'print $2'})
```
\*\* This process will take some time.  Wait for completion where you will want to copy the join command for later adding worker nodes to the cluster.

### Copy config from master (to workstation)
If you're on a Mac, use Homebrew to install `kubectl`
```
scp <master_node>:/etc/kubernetes/admin.conf ~/.kube/config
```

### Deploy the Flannel networking to the cluster (from workstation)
```
kubectl apply -f bootstrap-manifests/kube-flannel.yml
kubectl apply -f bootstrap-manifests/kube-flannel-rbac.yml
```

### Issue join command to each worker node
Paste in the command you copied from the output of your controller init on the master to each worker node
```
kubeadm join <master_ip>:6443 --token <token> --discovery-token-ca-cert-hash sha256:<cert_hash>
# enable read-only metric/stats for Heapster
sed -i s/$/\ --read-only-port=10255/g /var/lib/kubelet/kubeadm-flags.env
systemctl restart kubelet
```

### Get join command string at a later time
The tokens will change over time, so the original join command string will change if you add more workers
at a later time.

ssh to your controller node and issue the following command:
```
kubeadm token create --print-join-command
```

### Check nodes (from workstation)
```
kubectl get nodes
```
Wait until all the nodes are in Ready state before you continue...

### Install Dashboard (with Heapster, until metrics-server is supported)
```
kubectl apply -f bootstrap-manifests/heapster.yaml
kubectl apply -f bootstrap-manifests/influxdb.yaml
kubectl apply -f bootstrap-manifests/kubernetes-dashboard.yml
```
### get Dashboard access token
```
kubectl -n kube-system describe secret $(kubectl -n kube-system get secret  | grep dashboard-token | awk {'print $1'}) | grep token
```

### Access Dashboard in browser
From workstation:
```
kubectl get services kubernetes-dashboard -n kube-system
```
Note the 5 digit port number.  You can access the dashboard through your browser at `http://<controller-ip>:<port>`

Use the token to log in.

## Services Discovery and DNS

### MetalLB and pfSense DNS controller

The wonderful [MetalLB](https://metallb.universe.tf) will expose `LoadBalancer` type Services as external endpoints via Layer 2 networking (ARP).  Make sure to edit the IP address range in `metallb.yaml` to reflect an unused set of IPs that will be accessible within your local network.  In my case, I have my LAN interface on pfSense configured as 10.4.1.0/20 so that the 10.4.8.0/24 MetalLB assigned IPs are accessible.  

I implemented the `pfsense-dns-services` module of [travisghansen's](https://github.com/travisghansen) [kubernetes-pfsense-controller](https://github.com/travisghansen/kubernetes-pfsense-controller) for service discovery and DNS resolution.  The module will automatically update both or either pfSense's DNS services: DNS Forwarder (dnsmasq) and DNS Resolver (unbound) when you add an annotation of `dns.pfsense.org/hostname` to a Service's metadata.  See my `manifests` directory for examples.
```
kubectl apply -f bootstrap-manifests/metallb.yaml
kubectl apply -f bootstrap-manifests/pfsense-metallb-dns.yaml
```

** Once you've applied the manifest, you'll want to edit the secret for your pfSense password.  To generate the base64 encoded string for the secret, use the following command:

`echo -n '<yoursecret>' | base64`

Use the dashboard to edit the secret and update with this newly generated string.  You'll find the `kubernetes-pfsense-controller` secret under the `kube-system` namespace.


### Container logging via logzio
```
wget https://raw.githubusercontent.com/logzio/public-certificates/master/COMODORSADomainValidationSecureServerCA.crt
echo -n 'YOUR_LOGZIO_TOKEN_HERE' > token
kubectl create secret generic logzio --from-file=token --from-file=COMODORSADomainValidationSecureServerCA.crt -n kube-system
kubectl apply -f https://raw.githubusercontent.com/TheCase/k8s-logzio-filebeat/master/filebeat-kubernetes.yaml
```

### Install Helm and RBAC service accout and start
```
brew install kubernetes-helm
kubectl apply -f bootstrap-manifests/helm-tiller-rbac.yaml
helm init --service-account=helm
```

### Example helm deployment
```
helm install stable/concourse
```
