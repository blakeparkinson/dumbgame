/*jshint multistr:true */
text= "dkjsfj dksfjds dskfj blake nfdf djsfj \
sfjkdks blake kdfjsfj blake fjksj kdsfjsd \
dkjfk blake kjdfs lkfdk";
myName="blake";
hits=[];
for (i=0;i<text.length;i++){
  if (text[i]=='b') {
		for (j=i;(j<myName.length+i);j++){
			hits.push(text[j]);
			console.log(hits);
		}
	}
	else {console.log("Your name wasn't found")};
}
