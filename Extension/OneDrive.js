/**
 * Some constants
 */
var appidkey = "&appid="; // the URL key is for the app id
var appidval = "000000004813A94E"; // the actual app id
var filekey = "&filename="; // the URL key for the filename
var onedrivebaseurl = "http://1dapp.azurewebsites.net/?url=" // the base URL to upload from URL
//"http://onedrive.live.com/upload?url=" // one day...
var pdfbaseurl = "http://FreeHTMLtoPDF.com/?convert="; // the base URL to convert a URL to PDF
var pdfparams = "&size=US_Letter&orientation=landscape"; // the parameters for converting a URL to a PDF
var debug = true; // to send data to the console

/**
 * Some common functions
 */
function log(info, tab) { // logs some data to console if debug is true
	if(debug == true) {
		console.log("wanted to save " + info.menuItemId + " to OneDrive");
		console.log("info: " + JSON.stringify(info));
		console.log("tab: " + JSON.stringify(tab));
	}
}

function newTab(url) { // create a new Chrome tab from url
	chrome.tabs.create({"url": url});
}

function fileNameFromURL(url) { // get the filename from a URL
	return url.substring(url.lastIndexOf("/")+1);
}

// Save a URL to OneDrive
function saveLinkToOneDrive(info, tab) {
	log(info,tab);
	var url = onedrivebaseurl + encodeURIComponent(info.pageUrl) + appidkey + appidval + filekey + fileNameFromURL(info.pageUrl);
	newTab(url);
}

// Save the PDF version of a URL to OneDrive
function savePageToOneDrive(info, tab) {
	log(info,tab);
	var pdfUrl = pdfbaseurl + info.pageUrl + pdfparams;
	var url = onedrivebaseurl + encodeURIComponent(pdfUrl) + appidkey + appidval + filekey + fileNameFromURL(info.pageUrl);
	newTab(url);
}

// Save a media file to OneDrive
function saveContentToOneDrive(info, tab) {
	log(info,tab);
	var url = onedrivebaseurl + encodeURIComponent(info.srcUrl) + appidkey + appidval + filekey + fileNameFromURL(info.srcUrl);
	newTab(url);
}

/**
 * Main method (of sorts)
 */

// Create one item for each context type.
var contexts = ["page", "link", "image", "audio", "video"];

// Create the right click menu items
for (var i = 0; i < contexts.length; i++) {
	var context = contexts[i];
	var title = "Save " + context + " to OneDrive";

	var id;
	if(context == "page") { // if it's a page, save the URL
		id = chrome.contextMenus.create({"title": title, "contexts":[context], "onclick": savePageToOneDrive});	  
	}else if(context == "link"){ // if it's a link, save the PDF of the URL
		id = chrome.contextMenus.create({"title": title, "contexts":[context], "onclick": saveLinkToOneDrive});	
	}else{ // otherwise, it's probably media content so save the source
		id = chrome.contextMenus.create({"title": title, "contexts":[context], "onclick": saveContentToOneDrive});	
	}
	
	if(debug) {
		console.log(i + ": '" + context + "' item:" + id);
	}
}


