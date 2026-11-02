// Run with node tests/campaign_autosave.js. Execute the shipped client with a
// small DOM/network collaborator; this does not establish browser visual QA.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname,'../assets/campaigns.js'),'utf8');
function editor(existing = false) {
  const fields = Object.fromEntries(['name','target_radius_km','media_type','headline','message','destination_url','location_id'].map(n => [n,{name:n,value:n==='name'?'':n,selectedOptions:[{textContent:n}]}]));
  if (existing) fields.revision = {name:'revision',value:'a'.repeat(64)};
  const handlers={}, listeners={}, nodes={}, calls=[], pending=[];
  let task;
  const button={hidden:false};
  const form={action:'https://example.test/campaign_edit.php',hasAttribute:()=>false,
    querySelector:s=>s==='[type="submit"]'?button:fields[s.match(/name="(.*?)"/)[1]] || null,
    addEventListener:(name,fn)=>handlers[name]=fn,append:el=>{fields[el.name]=el},checkValidity:()=>Boolean(fields.name.value.trim())};
  const document={querySelector:s=>s==='[data-campaign-form]'?form:(nodes[s] ||= {textContent:'',hidden:true,addEventListener:(n,fn)=>listeners[s+n]=fn}),createElement:()=>({})};
  const fetch = (url,options)=>{calls.push({url,body:options.body});return new Promise((resolve,reject)=>pending.push({resolve,reject}))};
  class FormData {constructor(){this.values=Object.fromEntries(Object.entries(fields).map(([n,f])=>[n,f.value]))}set(n,v){this.values[n]=v}}
  vm.runInNewContext(source,{document,window:{fetch,FormData,location:{href:form.action},addEventListener:(n,fn)=>listeners[n]=fn},fetch,FormData,URL,history:{replaceState(){}},setTimeout:fn=>{task=fn;return 1},clearTimeout:()=>{task=null}});
  return {fields,nodes,calls,button,form,
    change(n,value){fields[n].value=value;handlers.input()},
    async run(){const run=task;task=null;if(run)await run()},
    async respond(status,payload){pending.shift().resolve({ok:status===200,status,redirected:false,json:async()=>payload});await new Promise(setImmediate)},
    async fail(){pending.shift().reject(new Error('Offline'));await new Promise(setImmediate)},
    leave(){let blocked=false;listeners.beforeunload({preventDefault(){blocked=true}});return blocked},
    retry(){listeners['[data-autosave-retry]click']()}};
}
(async()=>{
  const e=editor();assert(e.button.hidden);assert(!e.leave());
  e.change('headline','Content before a name');await e.run();assert.equal(e.calls.length,0);
  e.change('name','First draft');const first=e.run();assert.equal(e.calls.length,1);assert(e.leave());
  e.change('message','Typed while saving');assert.equal(e.calls.length,1);
  await e.respond(200,{id:7,revision:'b'.repeat(64)});await first;
  assert(e.leave());assert.equal(e.fields.revision.value,'b'.repeat(64));
  const second=e.run();assert.equal(e.calls[1].body.values.message,'Typed while saving');
  assert.equal(e.calls[1].body.values.revision,'b'.repeat(64));
  await e.respond(200,{id:7,revision:'c'.repeat(64)});await second;
  assert(!e.leave());assert.equal(e.nodes['[data-save-state]'].textContent,'Saved');
  e.change('headline','Conflict');const conflict=e.run();await e.respond(409,{error:'Changed elsewhere'});await conflict;
  e.change('message','Keep this');await e.run();assert.equal(e.calls.length,3);assert(e.leave());
  const newDraft=editor();newDraft.change('name','Uncertain');const lost=newDraft.run();await newDraft.fail();await lost;
  newDraft.change('message','Still here');await newDraft.run();assert.equal(newDraft.calls.length,1);assert(newDraft.leave());
  const edit=editor(true);edit.change('name','Existing');const failed=edit.run();await edit.fail();await failed;
  assert(!edit.nodes['[data-autosave-retry]'].hidden);edit.retry();assert.equal(edit.calls.length,2);
  await edit.respond(200,{id:8,revision:'d'.repeat(64)});assert(!edit.leave());
  console.log('PASS autosave name gate, single-flight queued edits, revision advance, saved state, conflict stop, ambiguous create stop and update retry');
})().catch(error=>{console.error(error);process.exitCode=1});
