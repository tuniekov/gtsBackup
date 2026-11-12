import fs from 'fs';
import axios from 'axios';
//import FormData from 'form-data';
import { loadEnv} from 'vite'
import prompt from 'prompt'

import os from "os";
import path from "path";
import {fileURLToPath} from 'url';

const mode ='development' //development
process.env = {...process.env,...loadEnv(mode, './'),...loadEnv(mode, '../')}
// console.log(process.env)
prompt.start();

prompt.get(['username','password'], async function (err, result) {
    if (err) { return onErr(err); }
    // console.log('Command-line input received:');
    console.log('Получаем токен для ' + result.username);

    let error = null;
    try {
        const url = `${process.env.VITE_APP_PROTOCOL}://${process.env.VITE_APP_HOST}/api/security/login`
        // Axios automatically serializes `{ answer: 42 }` into JSON.
        const res = await axios.post(url, { username: result.username, password: result.password })

        console.log(res.data)
        if(res.data.token){
            setEnvValue('VITE_DEV_TOKEN', res.data.token)
        }
    }catch (err) {
        error = err;
    }
    if(error)
        console.log('error axios',error.message)

});

function onErr(err) {
    console.log(err);
    return 1;
}

const __filename = fileURLToPath(import.meta.url);
let __dirname = path.dirname(__filename);
__dirname = path.dirname(__dirname);
const envFilePath = path.resolve(__dirname, "../.env");

// console.log('envFilePath',envFilePath)
// read .env file & convert to array
const readEnvVars = () => fs.readFileSync(envFilePath, "utf-8").split(os.EOL);

/**
 * Finds the key in .env files and returns the corresponding value
 *
 * @param {string} key Key to find
 * @returns {string|null} Value of the key
 */
const getEnvValue = (key) => {
  // find the line that contains the key (exact match)
  const matchedLine = readEnvVars().find((line) => line.split("=")[0] === key);
  // split the line (delimiter is '=') and return the item at index 2
  return matchedLine !== undefined ? matchedLine.split("=")[1] : null;
};

/**
 * Updates value for existing key or creates a new key=value line
 *
 * This function is a modified version of https://stackoverflow.com/a/65001580/3153583
 *
 * @param {string} key Key to update/insert
 * @param {string} value Value to update/insert
 */
const setEnvValue = (key, value) => {
  const envVars = readEnvVars();
  const targetLine = envVars.find((line) => line.split("=")[0] === key);
  if (targetLine !== undefined) {
    // update existing line
    const targetLineIndex = envVars.indexOf(targetLine);
    // replace the key/value with the new value
    envVars.splice(targetLineIndex, 1, `${key}="${value}"`);
  } else {
    // create new key value
    envVars.push(`${key}="${value}"`);
  }
  // write everything back to the file system
  fs.writeFileSync(envFilePath, envVars.join(os.EOL));
};

// examples
// console.log(getEnvValue('KEY_1'));
// setEnvValue('KEY_1', 'value 1')

