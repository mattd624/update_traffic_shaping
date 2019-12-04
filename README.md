# update_traffic_shaping
These scripts are for use in allowing our Salesforce database to talk to a server on our network we call the "SOAP listener", which in turn calls out to other systems on our network. For example, update_ubiq_tshaping.php is designed specifically to listen for messages coming from Salesforce, and then to talk to Ubiquiti AirMax devices and update the traffic shaping configuration. For you to use this code, you would need to modify at least some parts of it to work with your system. At the very least since my company uses custom objects and fields in Salesforce, these are not going to be the same as yours, so you will need to change the names of the fields to match those in your Salesforce db. There are also other scripts that are needed for this to run which are listed in the Includes section. I will add these in a different repo called common_php.