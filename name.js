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

var Todo = Backbone.Model.extend({
  // Default todo attribute values
  defaults: {
    title: '',
    completed: false
  }
});

var todo1 = new Todo();
console.log(todo1.get('title')); // empty string
console.log(todo1.get('completed')); // false

var todo2 = new Todo({
  title: "Retrieved with model's get() method.",
  completed: true
});
console.log(todo2.get('title')); // Retrieved with model's get() method.
console.log(todo2.get('completed')); // true


var Person = new Backbone.Model();
Person.on("change:name", function() { console.log('Name changed'); });
Person.set({name: 'Andrew'});
// log entry: Name changed

Person.set({name: 'Jeremy'}, {silent: true});
// no log entry

console.log(Person.hasChanged("name"));
// true: change was recorded
console.log(Person.hasChanged(null));
// true: something (anything) has changed
