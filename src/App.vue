<template>
  <UniTreePanel :treetabs="gtsbackup"/>
</template>

<script setup>
  import { PVTables, UniTreePanel } from 'pvtables/dist/pvtables'
  import { ref } from 'vue'
  const gtsbackup = ref({
    gsProductTree2:{
      type:'tree',
      title: 'Product Tree 2',
      table: 'gsProductTree2',
    },
    gsProductTree3:{
      type:'tree',
      title: 'Product Tree 3',
      table: 'gsProductTree3',
    },
  });
</script>
<style>
 #gtsbackup{
  display: flex;
  width: auto;
  height: 100vh;
 }
</style>