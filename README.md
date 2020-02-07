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

More information on pxe booting via IP and MAC addresses, see this [https://docs.oracle.com/cd/E24628_01/em.121/e27046/appdx_pxeboot.htm#EMLCM12198](https://docs.oracle.com/cd/E24628_01/em.121/e27046/appdx_pxeboot.htm#EMLCM12198)

## Bootstrap
There are scripts in the `proxmox` directory that will aid you in quickly provisioning the master and worker nodes.  They will boot on creation, find the pxe server, tftp boot the CoreOS images and apply the cloud-init configuration and run the kube-bootstrap shell script.  The nodes will be ready to run `kubeadm`, to either initialize a master (single or clustered) or join to a master as workers.  

### Controller Nodes

#### Single node Master

The easiest way to start is with a single master node:

```
kubeadm init --apiserver-advertise-address=$(ifconfig ens18 | grep "inet " | awk {'print $2'})
```

This process will take some time.  Wait for completion where you will want to copy the join command for later adding worker nodes to the cluster.  Copy all the resulting text from the kubeadm init command and save it for later use.  Move past the clustering instructions and on to [Start Weave Networking](#weave).

#### Clustered Control Plane
If you want to have two or more masters, you'll first want to set up a HAProxy Server to evenly distribute traffic before you set up the nodes, as you'll need the FQDN of the proxy for the initialization of the first control-plane node. (TODO: HAProxy walkthrough for pfSense).

Run the following on the first master node:

```
kubeadm init --control-plane-endpoint "<haproxy fqdn>:6443" --upload-certs
```

As with the single node, this process will take a few minutes.  If the process is successful, there will be a number of `kubeadm init` commands in the output.  I recommend you copy and paste all of the output to somewhere safe for later use.

##### CoreOS issue with control-plane installs

There is an issue with CoreOS and control-plane nodes, because the default flex volume plugin directory is read-only on CoreOS.  You'll notice this as the controller pod will be stuck in ContainerCreating status. You can check this with: 

```
kubectl get pods -n kube-system
```

We need to patch the controller manifest and kubelet configuration to use a different directory.  You will need to run this patcher on each control-plane node in your cluster (ie. not the worker nodes):

```
sh /opt/bin/flex-patch.sh
```
 
After a minute or so, the controller pod should start successfully.  You can verify with the following command on the master node:

```
kubectl get pods -n kube-system
```

##### Add more control-plane nodes

You'll want to add at least one additonal control-plane nodes to cluster the master nodes for HA.  SSH into a newly chosen master node.  If it has been more than two hours since you created the first master node, you'll need to upload new certificates.  You can also use this command if you forgot the master node join string from the first node:

```
kubeadm token create --print-join-command --certificate-key $(kubeadm init phase upload-certs --upload-certs | tail -1)
```

Apply the join command - and again we need to patch the controller for the read-only CoreOS bug (please note I have removed the sensitive data to display the example - you'll need the actual command from the token create ommand above):

```
kubeadm join kube-controller-lb.311cub.net:6443 --token <token> --discovery-token-ca-cert-hash sha256:<hash> --control-plane --certificate-key <cert-key>
sh /opt/bin/flex-patch.sh
```

###<a name="weave"></a> Deploy the Weave networking to a master
You only need to do this once on a master node: 

```
mkdir -p ~/.kube && cp /etc/kubernetes/admin.conf ~/.kube/config
sysctl net.bridge.bridge-nf-call-iptables=1 
kubectl apply -f "https://cloud.weave.works/k8s/net?k8s-version=$(kubectl version | base64 | tr -d '\n')"
```

### Copy config from master (to workstation)
If you're on a Mac, use Homebrew to install `kubectl`.  Then copy the config file from a master nodes.

```
scp <master_node>:/etc/kubernetes/admin.conf ~/.kube/config
```

### Issue join command to each worker node

If it has been more than two hours since you created the first master node, you'll need to generate a new token.  You can also use this command if you forgot the worker join string from the first node:
ssh to your controller node and issue the following command:

```
kubeadm token create --print-join-command
```

Paste in the worker join command you copied from the output of your controller init on the master to each worker node (please note I have removed the sensitive data to display the example - you'll need the actual command from the token create ommand above):
 
```
kubeadm join <master_ip>:6443 --token <token> --discovery-token-ca-cert-hash sha256:<cert_hash>
```

### Check nodes (from workstation)

```
kubectl get nodes
```

Wait until all the nodes are in Ready state before you continue...

Now we start adding base services...

## Services Discovery and DNS

### MetalLB and pfSense DNS controller

The wonderful [MetalLB](https://metallb.universe.tf) will expose `LoadBalancer` type Services as external endpoints via Layer 2 networking (ARP).  Make sure to edit the IP address range in `metallb.yaml` to reflect an unused set of IPs that will be accessible within your local network.  For example, if you have a LAN interface on pfSense configured as 10.1.1.0/22 so that the 10.1.3.0/24 MetalLB assigned IPs are accessible.  

I implemented the `pfsense-dns-services` module of [travisghansen's](https://github.com/travisghansen) [kubernetes-pfsense-controller](https://github.com/travisghansen/kubernetes-pfsense-controller) for service discovery and DNS resolution.  The module will automatically update both or either pfSense's DNS services: DNS Forwarder (dnsmasq) and DNS Resolver (unbound) when you add an annotation of `dns.pfsense.org/hostname` to a Service's metadata.  See my `manifests` directory for examples.  You may have to make changes to these files depending on your pfSense setup.

```
kubectl apply -f bootstrap-manifests/metallb.yaml
kubectl apply -f bootstrap-manifests/pfsense-metallb-dns.yaml
```

** Once you've applied the manifest, you'll want to edit the secret for your pfSense password.  To generate the base64 encoded string for the secret, use the following command:

```
echo -n '<yoursecret>' | base64
```

Edit the secret and update with this newly generated string.  You'll find the `kubernetes-pfsense-controller` secret under the `kube-system` namespace.  You'll want to update both the `pfsense-url` and `pfsense-password` (for the admin user)

```
kubectl get secrets kubernetes-pfsense-controller -o yaml -n kube-system > kpf-secret.yaml
# edit kpf-secret.yaml with your favorite editor
kubectl apply -n kube-system -f kpf-secret.yaml
```

#### Further exploration

I've included a sample `nginx` deployment in [manifests/nginx.yaml](manifests/nginx.yaml).

```kubectl apply -f manifests/nginx.yaml```

if all goes well, you'll see the new nginx DNS entry in either the Unbound or DNSForwarder (or both, depending on what you use) in pfSense.


#### On a Mac?  Check out the fantastic Kubernetic client!

It will work directly with your local `~/.kube/config` file!  Download, install and you off and adminstering your cluster!

Download: [https://docs.kubernetic.com](https://docs.kubernetic.com/)



### Questions?  Problems? 

 I'd love to help out.  File an issue here in this repo. 