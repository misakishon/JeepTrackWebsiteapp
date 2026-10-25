import test from 'node:test';
import assert from 'node:assert/strict';
import {TRIPS,JOURNEYS,ROUTES,DEFAULT_FILTERS,getData,breakdown,previousFilters,valid,reportBuckets} from '../../resources/js/admin/data.js';
import {csvBlob,pdfBlob} from '../../resources/js/admin/export.js';

test('reconciliation prevents manual/app duplicates without counting anonymous IDs',()=>{
    assert.equal(new Set(JOURNEYS.map(j=>j.reconciliationKey)).size,JOURNEYS.length);
    const data=getData(DEFAULT_FILTERS);
    assert.equal(data.metrics.unique,new Set(data.journeys.filter(j=>j.passenger).map(j=>j.passenger)).size);
    assert.ok(data.metrics.journeys>data.metrics.unique);
    assert.ok(data.journeys.some(j=>j.passenger===null));
});
test('route and direction filters reconcile trip, journey, and aggregate totals',()=>{
    const filters={...DEFAULT_FILTERS,route:ROUTES[0].id,direction:'Inbound'};
    const data=getData(filters),rows=breakdown(data,filters);
    const tripIds=new Set(data.trips.map(t=>t.id));
    assert.ok(data.trips.length>0&&data.journeys.length>0);
    assert.ok(data.trips.every(t=>t.route===filters.route&&t.direction==='Inbound'));
    assert.ok(data.journeys.every(j=>tripIds.has(j.trip)));
    assert.equal(rows.routeRows[0].trips,data.metrics.trips);
    assert.equal(rows.routeRows[0].journeys,data.metrics.journeys);
});
test('duration excludes invalid timestamps and comparison periods are equivalent',()=>{
    const data=getData(DEFAULT_FILTERS);
    assert.equal(data.timed.length,data.trips.filter(valid).length);
    assert.ok(data.timed.length<data.trips.length);
    assert.equal(data.metrics.duration,data.timed.reduce((a,t)=>a+(new Date(t.arrival)-new Date(t.departure))/60000,0)/data.timed.length);
    const previous=previousFilters(DEFAULT_FILTERS);
    assert.equal(new Date(previous.to)-new Date(previous.from),new Date(DEFAULT_FILTERS.to)-new Date(DEFAULT_FILTERS.from));
});
test('missing denominator and empty observations remain unavailable',()=>{
    const data=getData(DEFAULT_FILTERS),rows=breakdown(data,DEFAULT_FILTERS);
    assert.equal(rows.vehicleRows.find(v=>v.vehicle==='IJ-093').utilization,null);
    assert.equal(rows.driverRows[0].duration,null);
    const empty=getData({...DEFAULT_FILTERS,from:'2030-01-01',to:'2030-01-02'});
    assert.equal(empty.metrics.duration,null);
    assert.equal(empty.metrics.delayRate,null);
    assert.equal(empty.metrics.rating,null);
});
test('report grouping preserves totals and uses the selected bucket denominator',()=>{
    const data=getData(DEFAULT_FILTERS);
    for(const group of ['Daily','Weekly','Monthly']){
        const buckets=reportBuckets(data,DEFAULT_FILTERS,group);
        assert.equal(buckets.reduce((n,b)=>n+b.data.trips.length,0),data.metrics.trips);
        assert.equal(buckets.reduce((n,b)=>n+b.data.journeys.length,0),data.metrics.journeys);
        assert.ok(buckets.every(b=>b.filters.from>=DEFAULT_FILTERS.from&&b.filters.to<=DEFAULT_FILTERS.to));
    }
    const first=reportBuckets(data,DEFAULT_FILTERS,'Daily')[0];
    assert.equal(breakdown(first.data,first.filters).vehicleRows[0].available,12);
});
test('CSV contains only filtered columns, preserves quotes, and omits internal notes',async()=>{
    const rows=[{route:'Batac–Laoag',message:'Clear "stop" signs',internalNote:'Private admin note'}];
    const text=await csvBlob([{key:'route',label:'Route'},{key:'message',label:'Message'}],rows).text();
    assert.ok(text.includes('"Clear ""stop"" signs"'));
    assert.ok(!text.includes('Private admin note'));
});
test('generated PDF has valid cross-reference offsets and paginated tables',async()=>{
    const rows=Array.from({length:160},(_,i)=>({id:'T-'+i,route:'Batac–Laoag',duration:35}));
    const pdf=await pdfBlob({title:'Trip Summary',filters:'Oct 1–25 | Batac–Laoag',summary:[{label:'Trips',value:'160'}],columns:[{key:'id',label:'Trip'},{key:'route',label:'Route'},{key:'duration',label:'Duration'}],rows,charts:[{title:'Daily demand',kind:'line',data:[{label:'Oct 1',value:12},{label:'Oct 2',value:20}],unit:'Journeys'}],definitions:[['Duration','Valid arrival minus departure']],generated:'25 Oct 2026'}).text();
    assert.ok(pdf.startsWith('%PDF-1.4'));
    assert.ok(pdf.endsWith('%%EOF'));
    const offset=Number(pdf.match(/startxref\n(\d+)/)[1]);
    assert.equal(pdf.slice(offset,offset+4),'xref');
    const offsets=[...pdf.matchAll(/(\d{10}) 00000 n/g)].map(m=>Number(m[1]));
    offsets.forEach((o,i)=>assert.ok(pdf.slice(o).startsWith((i+1)+' 0 obj')));
    const count=Number(pdf.match(/\/Type \/Pages .*?\/Count (\d+)/)[1]);
    assert.ok(count>1);
});
